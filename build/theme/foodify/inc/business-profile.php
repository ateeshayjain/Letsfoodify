<?php
/**
 * WP-08 — the business's own identity: NAP, licence, and LocalBusiness schema.
 *
 * WHY THIS FILE EXISTS, WHICH IS NOT WHY I EXPECTED IT TO
 * ------------------------------------------------------
 * Google Business Profile ranking rests on NAP consistency — the name, address
 * and phone on the site matching the profile byte for byte. So I went to read
 * what the theme publishes, and found `FSSAI 10012345678901` hardcoded into the
 * site header, both footers and the trust strip.
 *
 * That is a placeholder. A real FSSAI licence is fourteen digits encoding state
 * and year; this is 1-00-1234567890-1, the number people type when they need a
 * number. **A food business displaying a fabricated licence number is a
 * compliance problem, not a typo** — and it was on every page of the build,
 * repeated four times, reading exactly like a real one.
 *
 * It is the fake-viewer-counter failure again in a more expensive place: a
 * plausible number that nobody would question. So the number is no longer a
 * literal anywhere. It comes from configuration, and when configuration is
 * empty the site says `NOT CONFIGURED` in the place a licence belongs —
 * unmistakably broken rather than quietly false — and scripts/smoke-test.sh
 * refuses to let it reach production.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Pure — tested in tests/business-test.php without WordPress.
 * ---------------------------------------------------------------------- */

/**
 * The dummy values this build shipped with, and any others worth catching.
 *
 * Matched case-insensitively as substrings. `example.com` and `yourdomain` are
 * here because they are the next thing to get left in.
 */
function foodify_known_placeholders(): array {
	return [
		'10012345678901',   // the FSSAI dummy that was in four templates
		'12345678901234',
		'example.com',
		'yourdomain',
		'lorem ipsum',
		'xxxxx',
	];
}

/** A real FSSAI licence number: exactly 14 digits, and not one of the dummies. */
function foodify_is_valid_fssai( string $value ): bool {
	$digits = preg_replace( '/\D/', '', $value ) ?? '';
	if ( 14 !== strlen( $digits ) ) {
		return false;
	}
	foreach ( foodify_known_placeholders() as $dummy ) {
		if ( $digits === preg_replace( '/\D/', '', $dummy ) ) {
			return false;
		}
	}
	// A licence starts with the licence-type digit 1 or 2. All-same digits and
	// a plain ascending run are not licences whatever their length.
	if ( ! preg_match( '/^[12]/', $digits ) ) {
		return false;
	}
	if ( preg_match( '/^(\d)\1{13}$/', $digits ) || '12345678901234' === $digits ) {
		return false;
	}
	return true;
}

/**
 * Which profile fields are missing or still placeholder text.
 *
 * @return array<int,string> Field names, empty when the profile is publishable.
 */
function foodify_business_placeholders( array $p ): array {
	$bad      = [];
	$required = [ 'legal_name', 'street', 'locality', 'region', 'postal', 'country', 'phone', 'email', 'fssai' ];

	foreach ( $required as $field ) {
		$value = trim( (string) ( $p[ $field ] ?? '' ) );
		if ( '' === $value ) {
			$bad[] = $field;
			continue;
		}
		foreach ( foodify_known_placeholders() as $dummy ) {
			if ( false !== stripos( $value, $dummy ) ) {
				$bad[] = $field;
				continue 2;
			}
		}
		// A masked phone number is display text that got copied into config.
		if ( 'phone' === $field && preg_match( '/[•*x]{2,}/i', $value ) ) {
			$bad[] = $field;
			continue;
		}
		if ( 'fssai' === $field && ! foodify_is_valid_fssai( $value ) ) {
			$bad[] = $field;
		}
	}
	return $bad;
}

/**
 * The LocalBusiness node, or null when the profile is not publishable.
 *
 * Returning null is the feature. Structured data is a machine-readable claim
 * about a real business; emitting one built from placeholders publishes a false
 * address and a false licence to Google in a format designed to be trusted.
 */
function foodify_local_business_schema( array $p ): ?array {
	if ( foodify_business_placeholders( $p ) ) {
		return null;
	}
	$node = [
		'@context' => 'https://schema.org',
		'@type'    => 'FoodEstablishment',
		'name'     => (string) $p['legal_name'],
		'address'  => [
			'@type'           => 'PostalAddress',
			'streetAddress'   => (string) $p['street'],
			'addressLocality' => (string) $p['locality'],
			'addressRegion'   => (string) $p['region'],
			'postalCode'      => (string) $p['postal'],
			'addressCountry'  => (string) $p['country'],
		],
		'telephone' => (string) $p['phone'],
		'email'     => (string) $p['email'],
	];
	if ( ! empty( $p['brand'] ) ) {
		$node['alternateName'] = (string) $p['brand'];
	}
	if ( ! empty( $p['url'] ) ) {
		$node['url'] = (string) $p['url'];
	}
	// The FSSAI licence, expressed the way schema.org can carry a licence.
	$node['hasCredential'] = [
		'@type'                => 'EducationalOccupationalCredential',
		'credentialCategory'   => 'FSSAI licence',
		'identifier'           => (string) $p['fssai'],
	];
	return $node;
}

/**
 * Text substituted into the templates. One table, so a token cannot exist in a
 * template with nothing replacing it — which is exactly how the copyright year
 * shipped as an invisible HTML comment.
 */
function foodify_content_tokens( array $p, string $year ): array {
	$fssai = trim( (string) ( $p['fssai'] ?? '' ) );
	return [
		'<!--FOODIFY_YEAR-->'  => $year,
		'<!--FOODIFY_FSSAI-->' => foodify_is_valid_fssai( $fssai )
			? $fssai
			// Deliberately shouty and deliberately not a number. A blank would
			// read as a layout bug; a plausible number is what got us here.
			: 'NOT CONFIGURED',
	];
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

/**
 * The profile. Address is from the brief (SCOPE.md §1); the licence is NOT,
 * and ships empty on purpose — see the note at the top of this file.
 */
function foodify_business_profile(): array {
	return (array) apply_filters( 'foodify_business_profile', [
		'legal_name' => 'AVAC Ventures',
		'brand'      => 'The Foodify Company',
		'street'     => 'N-7011 Parx Laureate, Sector 108',
		'locality'   => 'Noida',
		'region'     => 'UP',
		'postal'     => '201304',
		'country'    => 'IN',
		'phone'      => '',   // client to supply the number shown on GBP, identically
		'email'      => 'care@letsfoodify.com',
		'fssai'      => '',   // client to supply. Until then the site says NOT CONFIGURED.
		'url'        => home_url( '/' ),
		'place_id'   => '',   // GBP not created yet — WP-08 client dependency
	] );
}

/**
 * One substitution pass for every template token.
 *
 * Replaces the FOODIFY_YEAR filter that lived in functions.php. Two filters
 * doing the same job on render_block is how the second one gets forgotten; a
 * table means adding a token to a template without adding it here is a visible
 * un-replaced comment rather than a silent blank.
 */
add_filter( 'render_block', static function ( $html ) {
	if ( ! is_string( $html ) || false === strpos( $html, '<!--FOODIFY_' ) ) {
		return $html;   // cheap guard — runs for every block on every page
	}
	$tokens = foodify_content_tokens( foodify_business_profile(), wp_date( 'Y' ) );
	return str_replace( array_keys( $tokens ), array_map( 'esc_html', $tokens ), $html );
} );

/** Publish the business node — only when there is a real business to describe. */
add_action( 'wp_head', static function (): void {
	if ( ! is_front_page() && ! is_shop() ) {
		return;   // one place per site, not on every URL
	}
	$node = foodify_local_business_schema( foodify_business_profile() );
	if ( null === $node ) {
		return;
	}
	printf(
		'<script type="application/ld+json">%s</script>' . "\n",
		wp_json_encode( $node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
	);
}, 20 );

/** Say so in the admin, where someone can act on it. */
add_action( 'admin_notices', static function (): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$missing = foodify_business_placeholders( foodify_business_profile() );
	if ( ! $missing ) {
		return;
	}
	printf(
		'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <code>%3$s</code></p><p>%4$s</p></div>',
		esc_html__( 'Foodify:', 'foodify' ),
		esc_html__( 'the business profile is incomplete, so no LocalBusiness structured data is published and the FSSAI licence shows as NOT CONFIGURED. Missing or placeholder:', 'foodify' ),
		esc_html( implode( ', ', $missing ) ),
		esc_html__( 'These must match the Google Business Profile exactly — NAP consistency is what the profile is ranked on. Set them with the foodify_business_profile filter.', 'foodify' )
	);
} );
