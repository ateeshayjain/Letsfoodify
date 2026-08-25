<?php
/**
 * Taxonomy consolidation — 170 product tags down to the handful that earn their keep.
 *
 * The audit found 170 indexable tag archives serving 44 products. Tags like
 * "Quick Dinner Recipe" and "Restaurant Style Rice" each generate a near-empty page
 * that competes with the product page it should be sending traffic to.
 *
 * This does NOT guess the mapping. For each doomed tag it looks at which products
 * actually carry it, and redirects to the product category the most of them belong to.
 * A tag whose products land in one product goes to that product instead.
 *
 * RUN IT IN THREE PASSES, a month apart:
 *   wp eval-file taxonomy-cleanup.php report                # see what would happen
 *   wp eval-file taxonomy-cleanup.php noindex               # noindex,follow — let Google digest
 *   wp eval-file taxonomy-cleanup.php execute --confirm     # delete + write redirects.csv
 *
 * @package Foodify
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( "Run this through WP-CLI: wp eval-file taxonomy-cleanup.php report\n" );
}

$mode      = $args[0] ?? 'report';
$confirmed = in_array( '--confirm', $args, true );
$KEEP_MIN   = 5;   // a tag needs at least this many products to survive
$TARGET_MAX = 20;  // WP-02 acceptance criterion: indexable tag archives remaining
$OUT       = __DIR__ . '/redirects.csv';

$tags = get_terms( [
	'taxonomy'   => 'product_tag',
	'hide_empty' => false,
] );

if ( is_wp_error( $tags ) ) {
	WP_CLI::error( 'Could not read product tags: ' . $tags->get_error_message() );
}

WP_CLI::log( sprintf( 'Found %d product tags.', count( $tags ) ) );

$keep   = [];
$remove = [];

foreach ( $tags as $tag ) {
	if ( (int) $tag->count >= $KEEP_MIN ) {
		$keep[] = $tag;
	} else {
		$remove[] = $tag;
	}
}

/**
 * Work out the best redirect target for a tag, from the products that actually carry it.
 *
 * @return array{url:string, reason:string}
 */
$safe_term_link = static function ( $term, string $taxonomy = '' ): string {
	$link = $taxonomy ? get_term_link( $term, $taxonomy ) : get_term_link( $term );
	return is_wp_error( $link ) ? '/shop/' : wp_make_link_relative( (string) $link );
};

$resolve_target = static function ( WP_Term $tag ) use ( $safe_term_link ): array {
	$product_ids = get_posts( [
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => 100,
		'fields'         => 'ids',
		'tax_query'      => [ [
			'taxonomy' => 'product_tag',
			'field'    => 'term_id',
			'terms'    => $tag->term_id,
		] ],
	] );

	if ( ! $product_ids ) {
		return [ 'url' => '/shop/', 'reason' => 'no products' ];
	}

	// A tag on exactly one product should point at that product.
	if ( 1 === count( $product_ids ) ) {
		$permalink = get_permalink( $product_ids[0] );
		return [
			'url'    => $permalink ? wp_make_link_relative( (string) $permalink ) : '/shop/',
			'reason' => 'single product',
		];
	}

	// Otherwise: the product category the most of them share.
	$tally = [];
	foreach ( $product_ids as $pid ) {
		foreach ( wp_get_post_terms( (int) $pid, 'product_cat', [ 'fields' => 'ids' ] ) as $cat_id ) {
			$tally[ $cat_id ] = ( $tally[ $cat_id ] ?? 0 ) + 1;
		}
	}

	if ( ! $tally ) {
		return [ 'url' => '/shop/', 'reason' => 'no category' ];
	}

	arsort( $tally );
	$winner = (int) array_key_first( $tally );
	$share  = $tally[ $winner ];

	return [
		'url'    => $safe_term_link( $winner, 'product_cat' ),
		'reason' => sprintf( '%d/%d products in this category', $share, count( $product_ids ) ),
	];
};

/* ---------------------------------------------------------------- report */
if ( 'report' === $mode ) {
	WP_CLI::log( '' );
	WP_CLI::success( sprintf( 'KEEP %d tags (>= %d products):', count( $keep ), $KEEP_MIN ) );
	foreach ( $keep as $tag ) {
		WP_CLI::log( sprintf( '  %-42s %d products', $tag->name, $tag->count ) );
	}

	WP_CLI::log( '' );
	WP_CLI::warning( sprintf( 'REMOVE %d tags, with their redirect targets:', count( $remove ) ) );

	$rows = [];
	foreach ( $remove as $tag ) {
		$target = $resolve_target( $tag );
		$rows[] = [
			'tag'      => $tag->name,
			'products' => (int) $tag->count,
			'from'     => $safe_term_link( $tag ),
			'to'       => $target['url'],
			'why'      => $target['reason'],
		];
	}

	WP_CLI\Utils\format_items( 'table', $rows, [ 'tag', 'products', 'from', 'to', 'why' ] );
	WP_CLI::log( '' );
	WP_CLI::log( sprintf( 'Indexable tag archives: %d → %d', count( $tags ), count( $keep ) ) );

	// WP-02's criterion is "20 or fewer indexable tag archives remain". KEEP_MIN is the
	// rule; 20 is the target. Nothing reconciled the two, so a catalogue where 40 tags
	// clear the threshold would pass the script and fail the acceptance criterion.
	if ( count( $keep ) > $TARGET_MAX ) {
		WP_CLI::warning( sprintf(
			'%d tags would survive, but the acceptance criterion allows %d. Raise KEEP_MIN (currently %d) until this passes, or agree the criterion changes.',
			count( $keep ),
			$TARGET_MAX,
			$KEEP_MIN
		) );
	} else {
		WP_CLI::success( sprintf( 'Within the WP-02 target of %d indexable tag archives.', $TARGET_MAX ) );
	}
	exit;
}

/* --------------------------------------------------------------- noindex */
if ( 'noindex' === $mode ) {
	// Record what was there first. This touches ~150 terms and there was no way back.
	$prior = [];
	foreach ( $remove as $tag ) {
		$prior[ $tag->term_id ] = get_term_meta( $tag->term_id, 'rank_math_robots', true );
		update_term_meta( $tag->term_id, 'rank_math_robots', [ 'noindex', 'follow' ] );
	}
	update_option( 'foodify_taxonomy_noindex_prior', $prior, false );

	WP_CLI::success( sprintf(
		'%d tags set to noindex,follow. Prior values saved to the option foodify_taxonomy_noindex_prior.',
		count( $remove )
	) );
	WP_CLI::log( 'Leave them 30 days so Google drops them cleanly, then: execute --confirm' );
	WP_CLI::log( 'To reverse before then: wp eval-file taxonomy-cleanup.php undo-noindex' );
	exit;
}

if ( 'undo-noindex' === $mode ) {
	$prior = get_option( 'foodify_taxonomy_noindex_prior', [] );
	if ( ! $prior ) {
		WP_CLI::error( 'No saved prior state found. Nothing to undo.' );
	}
	foreach ( $prior as $term_id => $value ) {
		if ( '' === $value || [] === $value ) {
			delete_term_meta( (int) $term_id, 'rank_math_robots' );
		} else {
			update_term_meta( (int) $term_id, 'rank_math_robots', $value );
		}
	}
	delete_option( 'foodify_taxonomy_noindex_prior' );
	WP_CLI::success( sprintf( 'Restored robots meta on %d terms.', count( $prior ) ) );
	exit;
}

/* --------------------------------------------------------------- execute */
if ( 'execute' === $mode ) {
	if ( ! $confirmed ) {
		WP_CLI::error( 'This deletes terms. Re-run with --confirm once you have a database backup.' );
	}

	/*
	 * ORDER MATTERS AND IT USED TO BE WRONG.
	 *
	 * The original wrote redirects.csv, deleted every term, and told you to import the
	 * CSV into Rank Math afterwards. Between the delete and the import — however long
	 * that takes, and it is a manual step that can simply be forgotten — all ~150 URLs
	 * return 404. WP-02's own criterion is "zero soft-404s in Search Console after 14
	 * days", and this is the thing most likely to break it.
	 *
	 * A redirect installed while the term still exists is harmless: Rank Math fires the
	 * redirect before the archive renders. So the redirect goes in FIRST, and the term
	 * is only deleted once its redirect is confirmed present.
	 */
	$rm_db = 'RankMath\\Redirections\\DB';
	$can_install = class_exists( $rm_db ) && method_exists( $rm_db, 'add' );

	if ( ! $can_install && ! in_array( '--redirects-already-installed', $args, true ) ) {
		WP_CLI::error(
			"Rank Math's redirections API is not available, so this cannot install the redirects itself.\n" .
			"Deleting first would leave every tag URL a 404 until you import the CSV by hand.\n\n" .
			"Either:\n" .
			"  1. Enable the Redirections module (Rank Math -> Dashboard -> Modules), then re-run; or\n" .
			"  2. Run 'report', import the map yourself, verify with smoke-test.sh --redirects=...,\n" .
			"     then re-run with --redirects-already-installed to acknowledge."
		);
	}

	$fh = fopen( $OUT, 'wb' );
	if ( false === $fh ) {
		WP_CLI::error( 'Cannot write ' . $OUT );
	}
	fputcsv( $fh, [ 'source', 'target', 'type', 'note' ] );

	// The one URL typo the audit turned up.
	fputcsv( $fh, [ '/product/schezwagn-rice/', '/product/schezwan-rice/', '301', 'spelling fix' ] );

	$done = 0; $skipped = 0;
	foreach ( $remove as $tag ) {
		$target = $resolve_target( $tag );
		$from   = $safe_term_link( $tag );

		fputcsv( $fh, [ $from, $target['url'], '301', $target['reason'] ] );

		// Redirect first. Only delete a term whose replacement is already live.
		if ( $can_install ) {
			$added = $rm_db::add( [
				'sources'     => [ [ 'pattern' => ltrim( $from, '/' ), 'comparison' => 'exact' ] ],
				'url_to'      => $target['url'],
				'header_code' => 301,
				'status'      => 'active',
			] );
			if ( ! $added ) {
				WP_CLI::warning( sprintf( 'Redirect for "%s" did not install — leaving the term in place.', $tag->name ) );
				$skipped++;
				continue;
			}
		}

		$deleted = wp_delete_term( $tag->term_id, 'product_tag' );
		if ( is_wp_error( $deleted ) ) {
			WP_CLI::warning( sprintf( 'Could not delete "%s": %s', $tag->name, $deleted->get_error_message() ) );
			$skipped++;
			continue;
		}
		$done++;
	}

	fclose( $fh );

	WP_CLI::success( sprintf( 'Deleted %d tags (%d skipped). Redirect map written to %s', $done, $skipped, $OUT ) );
	WP_CLI::log( $can_install
		? 'Redirects were installed via Rank Math before each delete. The CSV is the audit record.'
		: 'Redirects were NOT installed by this script — you confirmed they were already in place.' );
	WP_CLI::log( 'Then verify with: ./smoke-test.sh https://letsfoodify.com --redirects=redirects.csv' );
	exit;
}

WP_CLI::error( "Unknown mode '$mode'. Use: report | noindex | undo-noindex | execute --confirm" );
