<?php
/**
 * The shop filters resolve their attribute on THIS install.
 *
 * WooCommerce's Attribute Filter block stores a numeric attributeId, assigned
 * when the attribute is created — so it differs between staging and production,
 * and a template exported from one filters nothing on the other. The templates
 * carry the SLUG instead and inc/product-attributes.php maps it at render time.
 * Both templates are also pinned identical: the category archive is the shop
 * archive with a different query, and they drifted once already.
 *
 *   php tests/shop-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );

foreach ( [ 'add_filter', 'add_action', 'str_starts_with' ] as $fn ) {
	if ( ! function_exists( $fn ) ) { eval( "function {$fn}() { return true; }" ); }
}
if ( ! function_exists( '__' ) ) { function __( $s, $d = '' ) { return $s; } }
require __DIR__ . '/../theme/foodify/inc/product-attributes.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}

$ids = static fn( string $slug ): int => [ 'prep' => 7, 'dietary' => 9 ][ $slug ] ?? 0;
$block = static fn( array $attrs, string $name = 'woocommerce/attribute-filter' ): array => [ 'blockName' => $name, 'attrs' => $attrs ];

echo "── the slug in the template becomes this install's id ──\n";

$out = foodify_resolve_filter_attribute( $block( [ 'attributeId' => 0, 'foodifyAttribute' => 'prep' ] ), $ids );
check( 'prep resolves to the id this install assigned',      7 === $out['attrs']['attributeId'] );
$out = foodify_resolve_filter_attribute( $block( [ 'attributeId' => 0, 'foodifyAttribute' => 'dietary' ] ), $ids );
check( 'dietary resolves independently',                     9 === $out['attrs']['attributeId'] );

echo "── and it stays honest when it cannot ──\n";

$out = foodify_resolve_filter_attribute( $block( [ 'attributeId' => 0, 'foodifyAttribute' => 'prep' ] ), static fn() => 0 );
check( 'an attribute that does not exist yet leaves 0 (block renders nothing, no invented id)', 0 === $out['attrs']['attributeId'] );
$out = foodify_resolve_filter_attribute( $block( [ 'attributeId' => 0, 'foodifyAttribute' => 'colour' ] ), $ids );
check( 'a slug the theme does not declare is ignored',       0 === $out['attrs']['attributeId'] );
$out = foodify_resolve_filter_attribute( $block( [ 'attributeId' => 42, 'foodifyAttribute' => 'prep' ] ), $ids );
check( 'an id set by hand in the Site Editor is respected',  42 === $out['attrs']['attributeId'] );
$out = foodify_resolve_filter_attribute( $block( [ 'attributeId' => 0 ], 'woocommerce/price-filter' ), $ids );
check( 'other blocks pass through untouched',                0 === $out['attrs']['attributeId'] );
$out = foodify_resolve_filter_attribute( $block( [ 'heading' => 'Prep method' ] ), $ids );
check( 'no slug, no change',                                 ! isset( $out['attrs']['attributeId'] ) );

echo "── the templates carry slugs, never ids, and agree with each other ──\n";

$tpl = __DIR__ . '/../theme/foodify/templates/';
$shop = (string) file_get_contents( $tpl . 'archive-product.html' );
$cat  = (string) file_get_contents( $tpl . 'taxonomy-product_cat.html' );
check( 'the category template IS the shop template',        $shop === $cat );
preg_match_all( '/wp:woocommerce\/attribute-filter\s*(\{.*?\})/', $shop, $m );
check( 'two attribute filters on the shop',                  2 === count( $m[1] ) );
$slugs = [];
foreach ( $m[1] as $json ) {
	$a = json_decode( $json, true );
	check( 'filter carries attributeId 0 — never an install-specific number', 0 === ( $a['attributeId'] ?? null ) );
	$slugs[] = $a['foodifyAttribute'] ?? '';
}
check( 'the filter slugs are exactly the attributes the theme registers', $slugs === foodify_attribute_slugs() );

echo "── the shop's controls are in the template, not hoped for ──\n";

check( 'a Filter control (details) wraps the filters',       str_contains( $shop, '<details class="wp-block-details fd-filters" open><summary>Filter</summary>' ) );
check( 'a visible "Sort by" label precedes the sort select', str_contains( $shop, 'fd-sort__label' ) && str_contains( $shop, 'wp:woocommerce/catalog-sorting' ) );
check( 'the product grid is the styled loop (fd-products)',  str_contains( $shop, '"className":"fd-products"' ) );
$home = (string) file_get_contents( $tpl . 'front-page.html' );
$pdp  = (string) file_get_contents( $tpl . 'single-product.html' );
check( 'home best-sellers use the same card loop',           str_contains( $home, '"className":"fd-products"' ) );
check( 'PDP related products use the same card loop',        str_contains( $pdp, '"className":"fd-products"' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
