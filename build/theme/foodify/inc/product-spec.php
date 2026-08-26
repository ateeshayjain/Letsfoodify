<?php
/**
 * The product page's structured facts — and how it makes it.
 *
 * DESIGN PASS, 2026-08-26. What was wrong with the page before this.
 *
 * 1. IT NEVER SAID HOW YOU MAKE IT. The brand's whole proposition is "a real
 *    meal in six minutes", and the single most important question a first-time
 *    buyer of instant food has — "what do I actually do with this?" — had no
 *    answer anywhere on the page. The homepage has a How-it-works pattern; the
 *    product page, where the decision is made, had a small chip.
 *
 * 2. THE COMPLIANCE TABLE WAS FURNITURE. Ten rows of equal weight titled "Pack &
 *    label", mixing things a buyer wants (net quantity, servings, allergens)
 *    with things a regulator wants (FSSAI number, marketed-by). Everything at
 *    one weight means nothing is findable, so the useful three were buried under
 *    the mandatory seven.
 *
 * 3. INGREDIENTS AND NUTRITION WERE MISSING ENTIRELY. Scope §8 lists both as
 *    required by the Legal Metrology e-commerce rules. Neither was in the
 *    template. That is a compliance gap wearing a design gap's clothes.
 *
 * So the facts are split by WHO IS ASKING — "What's in it" for the buyer,
 * "Pack & label" for the regulator — and both are structured fields, because
 * scope §8 is explicit that they must be enforceable and must feed the Merchant
 * Center product feed rather than being prose in a description.
 *
 * A MISSING DECLARATION IS SHOWN, NOT HIDDEN
 * ------------------------------------------
 * A required field with no value renders "Not provided" rather than being
 * dropped from the table. Dropping it makes an incomplete page look complete,
 * and the person who would notice is the one who cannot see the gap.
 *
 * For allergens this is a safety argument rather than a tidiness one: **the
 * absence of an allergen declaration must never read as "contains no
 * allergens"**. Someone deciding whether their child can eat this needs to know
 * the data is missing, not be shown a table that quietly omits the row.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Pure — tested in tests/product-spec-test.php without WordPress.
 * ---------------------------------------------------------------------- */

/**
 * Every declared field: label, which audience it serves, and whether the law
 * requires it.
 *
 * `required` follows scope §8 — the Legal Metrology e-commerce declarations plus
 * the FSSAI licence. Marked here rather than in a comment so the check that
 * enforces it reads the same list the page renders.
 */
function foodify_spec_fields(): array {
	return [
		// What's in it — the buyer's questions.
		'ingredients'  => [ 'label' => 'Ingredients',        'group' => 'contents', 'required' => true  ],
		'allergens'    => [ 'label' => 'Allergens',          'group' => 'contents', 'required' => true  ],
		'net_quantity' => [ 'label' => 'Net quantity',       'group' => 'contents', 'required' => true  ],
		'servings'     => [ 'label' => 'Servings per pack',  'group' => 'contents', 'required' => false ],
		'diet'         => [ 'label' => 'Veg / non-veg',      'group' => 'contents', 'required' => true  ],
		'storage'      => [ 'label' => 'Storage',            'group' => 'contents', 'required' => false ],

		// Pack & label — the regulator's questions.
		'mrp'          => [ 'label' => 'MRP',                'group' => 'label', 'required' => true  ],
		'best_before'  => [ 'label' => 'Best before',        'group' => 'label', 'required' => true  ],
		'shelf_life'   => [ 'label' => 'Shelf life',         'group' => 'label', 'required' => false ],
		'origin'       => [ 'label' => 'Country of origin',  'group' => 'label', 'required' => true  ],
		'fssai'        => [ 'label' => 'FSSAI licence',      'group' => 'label', 'required' => true  ],
		'marketed_by'  => [ 'label' => 'Marketed by',        'group' => 'label', 'required' => true  ],
		'care'         => [ 'label' => 'Consumer care',      'group' => 'label', 'required' => true  ],
	];
}

/**
 * Required declarations this product has not supplied.
 *
 * @param array<string,string> $values
 * @return array<int,string> Field keys.
 */
function foodify_spec_missing( array $values ): array {
	$missing = [];
	foreach ( foodify_spec_fields() as $key => $field ) {
		if ( ! $field['required'] ) {
			continue;
		}
		if ( '' === trim( (string) ( $values[ $key ] ?? '' ) ) ) {
			$missing[] = $key;
		}
	}
	return $missing;
}

/**
 * The render model: two groups of rows, each row either a value or a gap.
 *
 * @param array<string,string> $values
 * @return array<string,array{title:string,rows:array<int,array{key:string,label:string,value:string,provided:bool,required:bool}>}>
 */
function foodify_spec_model( array $values ): array {
	$groups = [
		'contents' => [ 'title' => "What's in it", 'rows' => [] ],
		'label'    => [ 'title' => 'Pack & label', 'rows' => [] ],
	];

	foreach ( foodify_spec_fields() as $key => $field ) {
		$value    = trim( (string) ( $values[ $key ] ?? '' ) );
		$provided = '' !== $value;

		// An optional field with nothing in it is simply not a row. A REQUIRED
		// one always is — see the note at the top of this file.
		if ( ! $provided && ! $field['required'] ) {
			continue;
		}

		$groups[ $field['group'] ]['rows'][] = [
			'key'      => $key,
			'label'    => $field['label'],
			'value'    => $provided ? $value : 'Not provided',
			'provided' => $provided,
			'required' => (bool) $field['required'],
		];
	}
	return $groups;
}

/**
 * Nutrition, per serving. Rendered as its own small table rather than as rows in
 * the list, because five numbers in a definition list read as five unrelated
 * facts and people scan nutrition as a block or not at all.
 *
 * @param array<string,string> $n
 * @return array<int,array{label:string,value:string}>
 */
function foodify_nutrition_rows( array $n ): array {
	$order = [
		'energy'  => 'Energy',
		'protein' => 'Protein',
		'carbs'   => 'Carbohydrate',
		'sugars'  => 'of which sugars',
		'fat'     => 'Fat',
		'sodium'  => 'Sodium',
	];
	$rows = [];
	foreach ( $order as $key => $label ) {
		$v = trim( (string) ( $n[ $key ] ?? '' ) );
		if ( '' === $v ) {
			continue;
		}
		$rows[] = [ 'label' => $label, 'value' => $v ];
	}
	// One or two stray numbers is not a nutrition panel; it is a fragment that
	// looks like a panel. Show it only when there is enough to be one.
	return count( $rows ) >= 3 ? $rows : [];
}

/**
 * How you make it — three steps, from the product's own prep method.
 *
 * Per product rather than one generic block, because "just add hot water" and
 * "requires cooking" are different promises and the page has to keep the one it
 * makes. A method nobody recognised returns nothing rather than inventing
 * instructions for food.
 *
 * @return array<int,array{n:int,title:string,detail:string}>
 */
function foodify_prep_steps( string $method, string $minutes = '' ): array {
	$m    = strtolower( trim( $method ) );
	$time = '' !== trim( $minutes ) ? trim( $minutes ) : '6 minutes';

	if ( false !== strpos( $m, 'hot water' ) ) {
		return [
			[ 'n' => 1, 'title' => 'Tip it into a bowl',   'detail' => 'The whole pack. No pan, no measuring.' ],
			[ 'n' => 2, 'title' => 'Add boiling water',    'detail' => 'To the line on the pack, and stir once.' ],
			[ 'n' => 3, 'title' => "Wait {$time}",         'detail' => 'Cover it. Stir again and eat.' ],
		];
	}
	if ( false !== strpos( $m, 'drinking water' ) || false !== strpos( $m, 'cold water' ) ) {
		return [
			[ 'n' => 1, 'title' => 'Tip it into a glass',  'detail' => 'The whole sachet.' ],
			[ 'n' => 2, 'title' => 'Add drinking water',   'detail' => 'Room temperature is fine.' ],
			[ 'n' => 3, 'title' => 'Stir and drink',       'detail' => 'No heating, no waiting.' ],
		];
	}
	if ( false !== strpos( $m, 'cook' ) ) {
		return [
			[ 'n' => 1, 'title' => 'Empty into a pan',     'detail' => 'With water as marked on the pack.' ],
			[ 'n' => 2, 'title' => "Simmer {$time}",       'detail' => 'Stir now and then so it does not catch.' ],
			[ 'n' => 3, 'title' => 'Rest, then serve',     'detail' => 'A minute off the heat thickens it.' ],
		];
	}
	return [];   // unknown method: say nothing rather than invent cooking instructions
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

/** Per-product declarations, from meta, with the business-wide ones filled in. */
function foodify_product_spec_values( WC_Product $product ): array {
	$meta = static fn( string $k ): string => (string) $product->get_meta( '_foodify_' . $k );

	$profile = function_exists( 'foodify_business_profile' ) ? foodify_business_profile() : [];
	$fssai   = (string) ( $profile['fssai'] ?? '' );

	$values = [
		'ingredients'  => $meta( 'ingredients' ),
		'allergens'    => $meta( 'allergens' ),
		'net_quantity' => $meta( 'net_quantity' ),
		'servings'     => $meta( 'servings' ),
		'diet'         => $meta( 'diet' ),
		'storage'      => $meta( 'storage' ),
		'mrp'          => $product->get_regular_price() ? wp_strip_all_tags( wc_price( (float) $product->get_regular_price() ) ) . ' (incl. all taxes)' : '',
		'best_before'  => $meta( 'best_before' ),
		'shelf_life'   => $meta( 'shelf_life' ),
		'origin'       => $meta( 'origin' ) ?: 'India',
		// The licence comes from ONE place, so it cannot be right in the footer
		// and wrong here. WP-08 renders it NOT CONFIGURED until the client
		// supplies it; here that means the row reads "Not provided", which is
		// the same truth in the register this table speaks.
		'fssai'        => function_exists( 'foodify_is_valid_fssai' ) && foodify_is_valid_fssai( $fssai ) ? $fssai : '',
		'marketed_by'  => trim( (string) ( $profile['legal_name'] ?? '' ) . ', ' . (string) ( $profile['locality'] ?? '' ) . ' ' . (string) ( $profile['postal'] ?? '' ) ),
		'care'         => (string) ( $profile['email'] ?? '' ),
	];

	return (array) apply_filters( 'foodify_product_spec_values', $values, $product );
}

function foodify_product_nutrition_values( WC_Product $product ): array {
	$n = [];
	foreach ( [ 'energy', 'protein', 'carbs', 'sugars', 'fat', 'sodium' ] as $k ) {
		$n[ $k ] = (string) $product->get_meta( '_foodify_nutrition_' . $k );
	}
	return (array) apply_filters( 'foodify_product_nutrition_values', $n, $product );
}

/** "How you make it" — directly under the buy box, where the question is asked. */
add_action( 'woocommerce_after_single_product_summary', static function (): void {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$method = function_exists( 'foodify_prep_method' ) ? foodify_prep_method( $product ) : '';
	$steps  = foodify_prep_steps( $method, (string) $product->get_meta( '_foodify_prep_minutes' ) );
	if ( ! $steps ) {
		return;
	}

	echo '<section class="fd-prep"><h2 class="fd-prep__title">' . esc_html__( 'How you make it', 'foodify' ) . '</h2><ol class="fd-prep__steps">';
	foreach ( $steps as $step ) {
		printf(
			'<li class="fd-prep__step"><span class="fd-prep__n">%1$d</span>'
			. '<span class="fd-prep__body"><strong>%2$s</strong><span>%3$s</span></span></li>',
			(int) $step['n'],
			esc_html( $step['title'] ),
			esc_html( $step['detail'] )
		);
	}
	echo '</ol></section>';
}, 6 );

/** The two fact tables, plus nutrition. */
add_action( 'woocommerce_after_single_product_summary', static function (): void {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$groups    = foodify_spec_model( foodify_product_spec_values( $product ) );
	$nutrition = foodify_nutrition_rows( foodify_product_nutrition_values( $product ) );

	echo '<div class="fd-spec">';

	foreach ( $groups as $key => $group ) {
		if ( ! $group['rows'] ) {
			continue;
		}
		printf( '<section class="fd-spec__group is-%1$s"><h2>%2$s</h2><dl>', esc_attr( $key ), esc_html( $group['title'] ) );
		foreach ( $group['rows'] as $row ) {
			printf(
				'<div%1$s><dt>%2$s</dt><dd>%3$s</dd></div>',
				$row['provided'] ? '' : ' class="is-missing"',
				esc_html( $row['label'] ),
				esc_html( $row['value'] )
			);
		}
		echo '</dl>';

		if ( 'contents' === $key && $nutrition ) {
			echo '<h3 class="fd-spec__nutrition-title">' . esc_html__( 'Nutrition, per serving', 'foodify' ) . '</h3>';
			echo '<table class="fd-nutrition"><tbody>';
			foreach ( $nutrition as $row ) {
				printf( '<tr><th scope="row">%1$s</th><td>%2$s</td></tr>', esc_html( $row['label'] ), esc_html( $row['value'] ) );
			}
			echo '</tbody></table>';
		}
		if ( 'label' === $key ) {
			printf(
				'<p class="fd-spec__note">%s</p>',
				esc_html__( 'These are the pack declarations. The same fields feed the Google product listing, so what you read here is what Google is told.', 'foodify' )
			);
		}
		echo '</section>';
	}
	echo '</div>';
}, 12 );

/**
 * Tell the shop, in the admin, which products are not legally complete.
 *
 * Legal Metrology declarations are per-product data, and per-product data is
 * exactly the kind that gets filled in for the first six and forgotten for the
 * other thirty-eight.
 */
add_action( 'admin_notices', static function (): void {
	if ( ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'wc_get_products' ) ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->id, [ 'edit-product', 'toplevel_page_foodify-today' ], true ) ) {
		return;
	}

	$incomplete = 0;
	foreach ( (array) wc_get_products( [ 'limit' => 100, 'status' => 'publish', 'return' => 'objects' ] ) as $p ) {
		if ( foodify_spec_missing( foodify_product_spec_values( $p ) ) ) {
			$incomplete++;
		}
	}
	if ( ! $incomplete ) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p></div>',
		esc_html__( 'Foodify compliance:', 'foodify' ),
		esc_html( sprintf(
			/* translators: %d: number of products */
			_n(
				'%d product is missing a declaration the Legal Metrology e-commerce rules require. Its page says "Not provided" where the value belongs.',
				'%d products are missing declarations the Legal Metrology e-commerce rules require. Their pages say "Not provided" where the values belong.',
				$incomplete,
				'foodify'
			),
			$incomplete
		) )
	);
} );
