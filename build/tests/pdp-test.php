<?php
/**
 * Wireframes 01–03: the card and product-page rules, as arithmetic.
 *
 * The preview renders these same functions with fixture data, so a rule that
 * holds here holds on the page the client reviews. Three rules worth pinning
 * by name:
 *
 *  - A SAVE badge below the floor is worse than none — it teaches the eye
 *    that the strike is fake. 14.9% is NOT rounded up to 15.
 *  - Sold out outranks Bestseller. A bestseller you cannot buy is sold out.
 *  - Pack & label is never a tab. It renders after them, always open.
 *
 *   php tests/pdp-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../theme/foodify/inc/product-spec.php';
require __DIR__ . '/../theme/foodify/inc/business-profile.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}

echo "── the yield line ──\n";
$full = [ 'net_quantity' => '80 g', 'cooked_weight' => '260 g', 'servings' => '2', 'prep_minutes' => '6 minutes' ];
check( 'all four facts, in order',       [ 'Net 80 g', 'Makes 260 g cooked', '2 servings', 'Ready in 6 min' ] === foodify_yield_parts( $full ) );
check( 'a missing fact is omitted, not invented', [ 'Net 80 g', 'Ready in 6 min' ] === foodify_yield_parts( [ 'net_quantity' => '80 g', 'prep_minutes' => '6' ] ) );
check( 'nothing known, nothing shown',   [] === foodify_yield_parts( [] ) );
check( 'one serving is singular',        [ '1 serving' ] === foodify_yield_parts( [ 'servings' => '1' ] ) );
check( '"6", "6 min", "6 minutes" all read 6 min', 'Ready in 6 min' === foodify_yield_parts( [ 'prep_minutes' => '6 min' ] )[0] );
check( 'card line: Serves 2 · 6 min',    'Serves 2 · 6 min' === foodify_card_yield( [ 'servings' => '2', 'prep_minutes' => '6' ] ) );
check( 'card line with no servings',     '6 min' === foodify_card_yield( [ 'prep_minutes' => '6' ] ) );
check( 'card line with nothing',         '' === foodify_card_yield( [] ) );

echo "── the SAVE badge floor ──\n";
check( 'floor is 15',                    15 === foodify_save_badge_min() );
check( '225 → 185 is 17%',               17 === foodify_save_percent( 225, 185 ) );
check( 'floors, never rounds up',        14 === foodify_save_percent( 100, 85.1 ) );
check( 'at the floor: badge',            'Save 15%' === foodify_save_badge( 100, 85 ) );
check( 'just under the floor: no badge (the Spice Up 3% mistake)', '' === foodify_save_badge( 100, 85.1 ) );
check( '13% (225 → 195): no badge',      '' === foodify_save_badge( 225, 195 ) );
check( 'no sale, no badge',              '' === foodify_save_badge( 185, 185 ) );
check( 'garbage prices: no badge',       '' === foodify_save_badge( 0, 185 ) && '' === foodify_save_badge( 185, 0 ) );

echo "── card badges: max two, sold out wins ──\n";
$b = foodify_card_badges( [ 'in_stock' => true, 'bestseller' => true, 'new' => true, 'dietary' => [ 'jain' ] ] );
check( 'bestseller + jain',              [ 'commercial' => 'Bestseller', 'dietary' => 'Jain' ] === $b );
$b = foodify_card_badges( [ 'in_stock' => false, 'bestseller' => true, 'dietary' => [] ] );
check( 'sold out outranks bestseller',   'Sold out' === $b['commercial'] && '' === $b['dietary'] );
$b = foodify_card_badges( [ 'in_stock' => true, 'new' => true ] );
check( 'new, no dietary',                [ 'commercial' => 'New', 'dietary' => '' ] === $b );
check( 'vegan alone is not a Jain badge', '' === foodify_card_badges( [ 'in_stock' => true, 'dietary' => [ 'vegan' ] ] )['dietary'] );
check( 'nothing to say renders nothing', '' === foodify_render_badges( foodify_card_badges( [ 'in_stock' => true ] ) ) );
$html = foodify_render_badges( [ 'commercial' => 'Sold out', 'dietary' => 'Jain' ] );
check( 'sold out gets the charcoal badge, Jain the leaf badge', str_contains( $html, 'fd-badge--out' ) && str_contains( $html, 'fd-badge--leaf' ) );
check( 'badge text is escaped',          str_contains( foodify_render_badges( [ 'commercial' => '<b>', 'dietary' => '' ] ), '&lt;b&gt;' ) );

echo "── taste chips and FAQ ──\n";
check( 'chips are trimmed and capped at four', [ 'A', 'B', 'C', 'D' ] === foodify_taste_chips( ' A , B,C ,D, E ' ) );
check( 'empty taste is no chips',        [] === foodify_taste_chips( '' ) );
$faq = foodify_faq_pairs( "Q: Stove?\nA: No.\nQ: Orphan question\nQ: Keeps?\nA: Three days." );
check( 'Q/A lines pair up; an unanswered question is dropped', [ [ 'q' => 'Stove?', 'a' => 'No.' ], [ 'q' => 'Keeps?', 'a' => 'Three days.' ] ] === $faq );
check( 'an answer with no question is ignored', [] === foodify_faq_pairs( "A: floating answer" ) );

echo "── the tabbed body ──\n";
$values = [ 'ingredients' => 'Lentils', 'allergens' => '', 'net_quantity' => '80 g', 'diet' => 'Vegetarian',
	'mrp' => '₹225', 'best_before' => '14 Aug 2027', 'origin' => 'India', 'fssai' => '', 'marketed_by' => 'AVAC', 'care' => 'care@x' ];
$d = [
	'about'     => '<p>Copy.</p>',
	'taste'     => [ 'Savoury' ],
	'groups'    => foodify_spec_model( $values ),
	'nutrition' => foodify_nutrition_rows( [ 'energy' => '312 kcal', 'protein' => '14 g', 'fat' => '8 g' ] ),
	'steps'     => foodify_prep_steps( 'hot water', '6' ),
	'faq'       => $faq,
];
$secs = foodify_pdp_sections( $d );
check( 'four sections when all four have content', [ 'about', 'ingredients', 'cook', 'faq' ] === array_column( $secs, 'id' ) );
$secs = foodify_pdp_sections( array_merge( $d, [ 'about' => '', 'taste' => [], 'faq' => [] ] ) );
check( 'an empty section is not a tab',  [ 'ingredients', 'cook' ] === array_column( $secs, 'id' ) );
$body = foodify_render_pdp_body( $d );
check( 'the first tab is open, the rest hidden', str_contains( $body, 'aria-expanded="true"' ) && substr_count( $body, ' hidden>' ) === 3 );
check( 'every button controls a panel',  substr_count( $body, 'aria-controls="fd-panel-' ) === 4 && substr_count( $body, 'role="region"' ) === 4 );
check( 'Pack & label is rendered AFTER the tabs, not as one', strrpos( $body, 'fd-label' ) > strrpos( $body, '</div>' ) - 2000 && ! str_contains( $body, 'fd-tab-label' ) );
check( 'the label table is never hidden', ! preg_match( '/fd-label[^>]*hidden/', $body ) );
check( 'missing declarations still say so, in both places', substr_count( $body, 'Not provided' ) === 2 && substr_count( $body, 'is-missing' ) === 2 );
check( 'nutrition renders inside the ingredients tab', str_contains( $body, 'fd-nutrition' ) );
check( 'both fact tables are the SAME table markup (one renderer, one set of CSS rules)',
	2 === substr_count( $body, '<dl class="fd-spec__list">' ) );
check( 'the step numbers keep their class (the contrast gate pins it)', str_contains( $body, 'fd-prep__n' ) );
// A product that has declared NOTHING still has required contents rows, and
// those rows are the point — "Not provided" for allergens is information a
// buyer needs, so it earns its tab. Only a genuinely empty contents group
// drops the tab strip entirely.
$bare = foodify_spec_model( [ 'mrp' => '₹225' ] );
check( 'undeclared contents are still a tab, because "Not provided" is the message',
	str_contains( foodify_render_pdp_body( [ 'groups' => $bare, 'nutrition' => [], 'steps' => [] ] ), 'fd-tabs' ) );
$bare['contents']['rows'] = [];
$body_no_tabs = foodify_render_pdp_body( [ 'groups' => $bare, 'nutrition' => [], 'steps' => [] ] );
check( 'no sections at all still renders the label table', ! str_contains( $body_no_tabs, 'fd-tabs' ) && str_contains( $body_no_tabs, 'fd-label' ) );

echo "── the one script ──\n";
$js = foodify_pdp_script();

// The [hidden] trap, found on the phone preview and worth a permanent gate.
// The script hides the sticky bar and the closed panels with el.hidden, which
// works only while nothing outranks the browser's own [hidden]{display:none} —
// a bare attribute selector that ANY class rule setting display beats. When
// that happens the attribute flips and the element stays on screen.
// Add a class here whenever the script hides something new.
$css = (string) file_get_contents( __DIR__ . '/../theme/foodify/style.css' );
foreach ( [ 'fd-sticky-atc', 'fd-tab__panel' ] as $cls ) {
	$sets_display = (bool) preg_match( '/\.' . preg_quote( $cls, '/' ) . '[^{}]*\{[^{}]*display:\s*(?!none)/', $css );
	$has_escape   = (bool) preg_match( '/\.' . preg_quote( $cls, '/' ) . '\[hidden\][^{}]*\{[^{}]*display:\s*none/', $css );
	check( ".$cls: either no display rule, or an explicit [hidden] escape from it",
		! $sets_display || $has_escape );
}
check( 'the script exists and is inert without the markup', str_contains( $js, 'data-fd-tabs' ) && str_contains( $js, 'IntersectionObserver' ) );
check( 'tabs on desktop, accordions below core\'s breakpoint', str_contains( $js, 'min-width:782px' ) );
check( 'it cannot end the script block early', ! str_contains( $js, '</script' ) );

echo "── tokens the templates read ──\n";
$t = foodify_content_tokens( [ 'fssai' => '' ], '2026', 'x' );
check( 'the delivery promise is a token',      'Ships across India in 3–5 days' === $t['<!--FOODIFY_DELIVERY-->'] );
check( 'the claim minutes are a token, from the one function', (string) foodify_claim_minutes() === $t['<!--FOODIFY_CLAIM_MINUTES-->'] );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
