<?php
/**
 * Remove orphaned Elementor postmeta after the builder is deleted.
 * Resolved through $wpdb so the table prefix is never guessed. Reports before it deletes.
 *
 * Usage: wp eval-file clean-elementor-meta.php          (dry run)
 *        wp eval-file clean-elementor-meta.php --apply
 */
declare( strict_types = 1 );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( "Run through WP-CLI: wp eval-file clean-elementor-meta.php\n" );
}

global $wpdb;

$apply    = in_array( '--apply', $args ?? [], true );
$patterns = [ '\_elementor\_%', '\_wpb\_%' ];

$total = 0;
foreach ( $patterns as $pattern ) {
	$count = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $pattern )
	);
	WP_CLI::log( sprintf( '  %-16s %d rows', $pattern, $count ) );
	$total += $count;

	if ( $apply && $count > 0 ) {
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $pattern )
		);
	}
}

if ( ! $apply ) {
	WP_CLI::warning( sprintf( '%d rows would be deleted. Re-run with --apply once you have a backup.', $total ) );
} else {
	WP_CLI::success( sprintf( 'Deleted %d orphaned meta rows.', $total ) );
}
