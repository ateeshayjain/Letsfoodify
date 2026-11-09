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
// Most of the range needs no cooking — hot water, or a stir of cold water.
check( 'the steps tab says "How to make", not "How to cook"', 'How to make' === ( array_column( $secs, 'title', 'id' )['cook'] ?? '' ) );
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
// A combo's bar said "1 pack" for a box of three. The unit comes from the bar.
check( 'the sticky bar names the unit the page sells (pack or box), not always "pack"',
	str_contains( $js, "data-unit-one" ) && str_contains( $js, "data-unit-many" )
	&& str_contains( (string) file_get_contents( __DIR__ . '/../theme/foodify/inc/product-display.php' ), "__( 'box', 'foodify' )" ) );
check( 'tabs on desktop, accordions below core\'s breakpoint', str_contains( $js, 'min-width:782px' ) );
check( 'it cannot end the script block early', ! str_contains( $js, '</script' ) );

echo "── tokens the templates read ──\n";
$t = foodify_content_tokens( [ 'fssai' => '' ], '2026', 'x' );
check( 'the delivery promise is a token',      'Ships across India in 3–5 days' === $t['<!--FOODIFY_DELIVERY-->'] );
check( 'the claim minutes are a token, from the one function', (string) foodify_claim_minutes() === $t['<!--FOODIFY_CLAIM_MINUTES-->'] );

echo "── PRODUCT-1 (P0): one delivery answer, not two ──\n";
// With the PIN zones configured the product page answers "when?" per PIN —
// up to ten days. A fixed "3–5 days" chip above that answer contradicts it.
$z = foodify_content_tokens( [ 'fssai' => '' ], '2026', 'x', true );
check( 'zones configured: the promise token is EMPTY',               '' === $z['<!--FOODIFY_DELIVERY-->'] );
check( 'zones configured: the announcement-bar segment is empty too', '' === $z['<!--FOODIFY_DELIVERY_BAR-->'] );
check( 'no zones: the promise prints, as before',                     'Ships across India in 3–5 days' === $t['<!--FOODIFY_DELIVERY-->'] );
check( 'no zones: the bar segment carries its own separator',         ' · Ships across India in 3–5 days' === $t['<!--FOODIFY_DELIVERY_BAR-->'] );
check( 'the default is "no zones" (callers that do not know keep today\'s page)', $t === foodify_content_tokens( [ 'fssai' => '' ], '2026', 'x', false ) );
$tpl_pdp = (string) file_get_contents( __DIR__ . '/../theme/foodify/templates/single-product.html' );
preg_match( '~<p class="fd-promise[^"]*">.*?</p>~', $tpl_pdp, $pill );
$rendered = str_replace( array_keys( $z ), array_values( $z ), $pill[0] ?? '' );
check( 'the template\'s pill, rendered with zones on, is removed whole (no empty chip)',
	'' !== ( $pill[0] ?? '' ) && '' === foodify_drop_empty_promise( $rendered ) );
check( '… and kept whole when there is a promise to print',
	str_contains( foodify_drop_empty_promise( str_replace( array_keys( $t ), array_values( $t ), $pill[0] ?? '' ) ), 'Ships across India' ) );
check( 'sold out: the pill goes whatever it says (no dispatch promise)', '' === foodify_drop_empty_promise( str_replace( array_keys( $t ), array_values( $t ), $pill[0] ?? '' ), true ) );
$bp = (string) file_get_contents( __DIR__ . '/../theme/foodify/inc/business-profile.php' );
check( 'the render filter asks the zones config, passes it to the tokens, and drops the empty pill',
	str_contains( $bp, "\$zones  = function_exists( 'foodify_delivery_config' ) && null !== foodify_delivery_config();" )
	&& (bool) preg_match( "/foodify_content_tokens\\( foodify_business_profile\\(\\), [^;]*, \\\$zones \\);/", $bp )
	&& str_contains( $bp, 'return foodify_drop_empty_promise( str_replace( array_keys( $tokens )' ) && str_contains( $bp, '$tokens ), $html ), $sold_out );' ) );

echo "── PRODUCT-3/10: the buy column order (decision 5) ──\n";
$col = substr( $tpl_pdp, (int) strpos( $tpl_pdp, 'fd-pdp__buy' ), (int) strpos( $tpl_pdp, '<!-- /wp:columns -->' ) - (int) strpos( $tpl_pdp, 'fd-pdp__buy' ) );
$at  = static fn( string $needle ): int => false === strpos( $col, $needle ) ? -1 : (int) strpos( $col, $needle );
$order = [ 'wp:post-title', 'wp:woocommerce/product-rating', 'wp:woocommerce/product-price', 'wp:woocommerce/add-to-cart-form', 'wp:post-excerpt' ];
$pos   = array_map( $at, $order );
check( 'title, rating, price, Add to cart, then the excerpt', ! in_array( -1, $pos, true ) && $pos === array_values( array_unique( $pos ) ) && $pos == ( static function ( $p ) { sort( $p ); return $p; } )( $pos ) );
check( 'nothing between the price and the add-to-cart form but the stock line',
	'' === trim( (string) preg_replace( '~<!-- wp:woocommerce/product-stock-indicator /-->~', '', substr( $col, $at( 'wp:woocommerce/product-price {"fontSize":"2xl"} /-->' ) + strlen( 'wp:woocommerce/product-price {"fontSize":"2xl"} /-->' ), $at( '<!-- wp:woocommerce/add-to-cart-form' ) - $at( 'wp:woocommerce/product-price {"fontSize":"2xl"} /-->' ) - strlen( 'wp:woocommerce/product-price {"fontSize":"2xl"} /-->' ) ) ) ) );
check( 'no assurance is typed into the template any more', ! str_contains( $tpl_pdp, 'fd-assure__item' ) && ! str_contains( $tpl_pdp, '9–12 month' ) && ! str_contains( $tpl_pdp, 'FOODIFY_CLAIM_MINUTES' ) );

echo "── PRODUCT-4: assurances from the product's own data ──\n";
$al = foodify_assurance_lines( [ 'shelf_life' => '12 months', 'cod' => 'Cash on delivery available', 'in_stock' => true ] );
check( 'no "Ready in N minutes" line — the yield strip states the product\'s own time', [] === preg_grep( '/ready in|minute/i', $al ) );
check( 'shelf life is the product\'s, not a typed "9–12 month"', in_array( 'Shelf life: 12 months', $al, true ) && [] === preg_grep( '/9–12/', $al ) );
check( 'no declared shelf life → no shelf-life line', [] === preg_grep( '/shelf/i', foodify_assurance_lines( [ 'shelf_life' => '' ] ) ) );
check( 'COD is an assurance', in_array( 'Cash on delivery available', $al, true ) );
check( 'sold out: no COD (a line about buying)', [ 'No preservatives', 'Shelf life: 12 months' ] === foodify_assurance_lines( [ 'shelf_life' => '12 months', 'cod' => 'Cash on delivery available', 'in_stock' => false ] ) );
check( 'PRODUCT-14: the note in customer words', 'Delivery charge, if any, shows in your cart. MRP includes all taxes.' === foodify_assurance_note( true ) );
check( 'sold out: the note drops the delivery half', 'MRP includes all taxes.' === foodify_assurance_note( false ) );
$ah = foodify_assurance_html( $al, foodify_assurance_note( true ) );
check( 'rendered as .fd-assure items + one note', 3 === substr_count( $ah, 'class="fd-assure__item"' ) && str_contains( $ah, '<p class="fd-assure__note">' ) );
check( 'PRODUCT-14: the old "not at the last screen" copy is gone from the theme', ! str_contains( $tpl_pdp, 'not at the last screen' ) );

echo "── PRODUCT-5: diet badges on the product page ──\n";
check( 'slugs in, labels out, fixed order', [ 'Jain', 'Vegan', 'Gluten free' ] === foodify_diet_tags( [ 'gluten-free', 'vegan', 'jain' ], 'Coconut, red chilli, salt' ) );
check( 'labels in (the prototype) work too', [ 'Vegan', 'Gluten free', 'Millet based' ] === foodify_diet_tags( [ 'Vegan', 'Gluten free', 'Millet based' ] ) );
check( 'Jain obeys the ingredient check, as on the card', [ 'Vegan' ] === foodify_diet_tags( [ 'jain', 'vegan' ], 'Rice, onion, salt' ) );
check( 'an unknown value is dropped, not printed raw', [] === foodify_diet_tags( [ '<script>' ] ) );
$tags = foodify_pdp_tags_html( 'Bestseller', [ 'Jain', 'Gluten free' ] );
check( 'Bestseller and the diet tags, as badges', '<p class="fd-pdp-tags"><span class="fd-badge fd-badge--flame">Bestseller</span><span class="fd-badge fd-badge--leaf">Jain</span><span class="fd-badge fd-badge--leaf">Gluten free</span></p>' === $tags );
check( 'nothing to say, nothing printed', '' === foodify_pdp_tags_html( '', [] ) );
check( 'the H1 filter prints them on the product page', str_contains( (string) file_get_contents( __DIR__ . '/../theme/foodify/inc/product-display.php' ), 'foodify_pdp_tags_html(' ) );

echo "── BROWSE-1: the card leads with the prep method ──\n";
$cp = foodify_card_prep_html( [ 'label' => 'Hot water · 6 min', 'modifier' => 'fd-chip--hot' ], 2 );
check( '"[Hot water · 6 min] Serves 2" — no dot to strand when it wraps', '<p class="fd-card-prep"><span class="fd-chip fd-chip--hot">Hot water&nbsp;· <span class="fd-card-prep__time">6 min</span></span><span class="fd-card-prep__serves">Serves 2</span></p>' === $cp );
check( 'no servings, just the chip', '<p class="fd-card-prep"><span class="fd-chip">Drinking water&nbsp;· <span class="fd-card-prep__time">1 min</span></span></p>' === foodify_card_prep_html( [ 'label' => 'Drinking water · 1 min', 'modifier' => '' ] ) );
check( 'a long method wraps only after the dot, and the time moves whole (client, 9 Nov)', str_contains( (string) file_get_contents( __DIR__ . '/../theme/foodify/style.css' ), '.fd-card-prep__time { white-space: nowrap; }' ) );
check( 'a label without a time is printed as it is', str_contains( foodify_card_prep_html( [ 'label' => 'Ready to eat', 'modifier' => '' ] ), '>Ready to eat</span>' ) );
check( 'no method, no line (the old yield line stays)', '' === foodify_card_prep_html( [] ) );
$pd = (string) file_get_contents( __DIR__ . '/../theme/foodify/inc/product-display.php' );
check( 'the card filter puts the chip BEFORE the title and drops the duplicate yield line',
	str_contains( $pd, 'return $lead . $html .' ) && str_contains( $pd, "\$yield = '' !== \$lead ? '' :" ) );

echo "── BROWSE-18: diet tags as text under the card's name ──\n";
check( 'two tags at most, Jain first, one line', '<p class="fd-card-diet">Jain · Vegan</p>' === foodify_card_diet_html( foodify_diet_tags( [ 'gluten-free', 'vegan', 'jain' ] ) ) );
check( 'no tags, no line', '' === foodify_card_diet_html( [] ) );
check( 'the image keeps only the commercial badge', str_contains( $pd, "foodify_render_badges( [ 'dietary' => '' ] + foodify_card_badges(" ) );

echo "── BROWSE-7: the in-cart button says what pressing it does ──\n";
check( 'WooCommerce\'s "%s in cart" becomes "Add another · %s in cart"', "Add another\u{00A0}· %s in cart" === foodify_in_cart_text() );
check( '… keeping the %s WooCommerce fills (PHP sprintf) and the ### it swaps (JS)', 1 === substr_count( foodify_in_cart_text(), '%s' ) && '1' === (string) preg_replace( '/\D/', '', sprintf( foodify_in_cart_text(), '1' ) ) );
check( 'hooked on the woocommerce text domain, that string only', str_contains( $pd, "add_filter( 'gettext_woocommerce'" ) && str_contains( $pd, "'%s in cart' === \$text" ) );

echo "── BROWSE-8: sold out last, in every sort ──\n";
$c = foodify_sold_out_last_clauses( [ 'join' => '', 'orderby' => 'wp_posts.post_date DESC' ], 'wp_posts', 'wp_wc_product_meta_lookup' );
check( 'joins the lookup table on the product id', str_contains( $c['join'], 'LEFT JOIN wp_wc_product_meta_lookup fd_stock ON fd_stock.product_id = wp_posts.ID' ) );
check( 'sold out first key, the chosen sort second', "( fd_stock.stock_status = 'outofstock' ) ASC, wp_posts.post_date DESC" === $c['orderby'] );
check( 'idempotent — a second pass changes nothing', $c === foodify_sold_out_last_clauses( $c, 'wp_posts', 'wp_wc_product_meta_lookup' ) );
check( 'an empty ORDER BY gets just the stock key', "( fd_stock.stock_status = 'outofstock' ) ASC" === foodify_sold_out_last_clauses( [ 'join' => '', 'orderby' => '' ], 'p', 'l' )['orderby'] );
check( 'applied to Product Collection queries and the main catalogue query', str_contains( $pd, "add_filter( 'posts_clauses'" ) && str_contains( $pd, "isProductCollection" ) );

echo "── PRODUCT-16 / BROWSE-21 / PRODUCT-20 ──\n";
$sp = foodify_sale_price_html( '<span>₹225</span>', '<span>₹185</span>', '₹225' );
check( 'a reader hears "MRP ₹225, now ₹185"', str_starts_with( $sp, '<span class="fd-sr">MRP ₹225, now </span><del aria-hidden="true">' ) && str_contains( $sp, '<ins><span>₹185</span></ins>' ) );
check( 'the sale-price filter is hooked', str_contains( $pd, "add_filter( 'woocommerce_format_sale_price'" ) );
check( 'sold-out card button names what it does', 'Notify me when Masala Oats is back' === foodify_sold_out_button_label( 'Masala Oats', true ) && 'Masala Oats is sold out' === foodify_sold_out_button_label( 'Masala Oats', false ) );
check( '"Contains: milk, gluten" from the declared allergens',
	'<p class="fd-allergens"><strong>Contains:</strong> milk, gluten</p>' === foodify_allergen_line_html( 'Milk (ghee), Cereals containing gluten (wheat, in the asafoetida)' ) );
check( 'none declared → no line', '' === foodify_allergen_line_html( "None of FSSAI's declarable allergens" ) && '' === foodify_allergen_line_html( '' ) );
check( '"may contain" is never shortened into "contains"', '' === foodify_allergen_line_html( 'May contain traces of peanuts' ) && [ 'milk' ] === foodify_allergen_names( 'Milk; may contain traces of tree nuts' ) );
$rows = foodify_spec_rows_html( foodify_spec_model( [ 'allergens' => 'Milk' ] )['contents']['rows'] );
check( 'the allergens row is marked for bold', str_contains( $rows, '<div class="fd-spec__row--allergens"><dt>Allergens</dt>' ) );
check( 'PRODUCT-14: no "what Google is told" on the page', ! str_contains( foodify_render_pdp_body( [ 'groups' => foodify_spec_model( [ 'mrp' => '₹1' ] ), 'nutrition' => [], 'steps' => [] ] ), 'Google' ) );

echo "── PRODUCT-2: the sticky bar, as behaviour (node tests/pdp-scripts.js) ──\n";
check( 'the bar waits for the form to leave the TOP of the viewport', str_contains( $js, 'e.isIntersecting||e.boundingClientRect.top>0' ) );
$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
check( 'node is available to run the page scripts', '' !== $node );   // a missing node is a FAIL, not a skip
if ( '' !== $node ) {
	exec( escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/pdp-scripts.js' ) . ' 2>&1', $ps_out, $ps_rc );
	$ps_last = (string) preg_replace( '/\e\[[\d;]*m/', '', (string) end( $ps_out ) );
	check( 'the scripts behave — ' . $ps_last, 0 === $ps_rc && (bool) preg_match( '/^\d+ passed, 0 failed$/', $ps_last ) );
}

echo "── pack size on the card (client, 9 Nov) ──\n";
check( 'the second line is "80 g · Serves 2" (where a caller still asks for it)', str_contains( foodify_card_prep_html( [ 'label' => 'Hot water · 6 min', 'modifier' => '' ], 2, '80 g' ), '<span class="fd-card-prep__serves">80 g · Serves 2</span>' ) );
check( 'a pack with no servings (a box) shows the pack alone', str_contains( foodify_card_prep_html( [ 'label' => 'Hot water · 6 min', 'modifier' => '' ], 0, '3 × 80 g' ), '>3 × 80 g</span>' ) );
check( 'no pack declared: servings alone, as before', str_contains( foodify_card_prep_html( [ 'label' => 'x', 'modifier' => '' ], 2 ), '>Serves 2</span>' ) );
$pdsrc = (string) file_get_contents( __DIR__ . '/../theme/foodify/inc/product-display.php' );
check( 'the card reads the declared net quantity — beside its unit price now', str_contains( $pdsrc, "foodify_card_pack_unit( \$html, (string) \$product->get_meta( '_foodify_net_quantity' ) )" ) );

echo "── the card, decluttered (client, 9 Nov: \"too cluttered\") ──\n";
check( 'under the name, one line: how it is made — no servings, no pack', str_contains( $pdsrc, '$lead  = $chip ? foodify_card_prep_html( $chip ) : \'\';' ) );
check( 'the diet tags are no longer printed on the card (filters, search and the product page carry them)', ! str_contains( $pdsrc, '= foodify_card_diet_html(' ) );
$pu = foodify_card_pack_unit( '<div class="p"><ins>₹185</ins><span class="fd-unit-price">₹231.25/100 g</span></div>', '80 g' );
check( 'the foot reads "80 g · ₹231/100 g", each half unbroken', str_contains( $pu, '<span class="fd-unit-price"><span class="fd-card-pack">80 g</span> · <span class="fd-card-unit">₹231/100 g</span></span>' ) );
check( 'the card rounds the unit price to the rupee (half up; thousands grouped)', str_contains( foodify_card_pack_unit( '<span class="fd-unit-price">₹216.67/100 g</span>', '90 g' ), '₹217/100 g' ) && str_contains( foodify_card_pack_unit( '<span class="fd-unit-price">₹1,216.49/kg</span>', '1 kg' ), '₹1,216/kg' ) );
check( 'no unit price: the pack alone; no pack: the block untouched', str_contains( foodify_card_pack_unit( '<div>₹99</div>', '2 × 80 g' ), '<span class="fd-unit-price"><span class="fd-card-pack">2 × 80 g</span></span></div>' ) && '<div>x</div>' === foodify_card_pack_unit( '<div>x</div>', '' ) );
$css = (string) file_get_contents( __DIR__ . '/../theme/foodify/style.css' );
check( 'one badge on the photo: a real saving hides Bestseller; Sold out hides the saving', str_contains( $css, '.fd-products > .wp-block-post:has(.wc-block-components-product-price .fd-save) .fd-badges .fd-badge--flame { display: none; }' ) && str_contains( $css, '.fd-products > .wp-block-post:has(.fd-badges .fd-badge--out) .wc-block-components-product-price .fd-save { display: none; }' ) );
check( 'the stepper on a card is drawn like Add (white, ink outline), not a solid pill', str_contains( $css, ".fd-products .fd-cartctl__stepper, .fd-again .fd-cartctl__stepper {\n  background: var(--wp--preset--color--surface); color: var(--wp--preset--color--flame-ink);" ) );
check( 'a combo\'s contents line is one line, so it cannot stretch its row', str_contains( $css, '.fd-products .fd-yield { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 1; overflow: hidden; }' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
