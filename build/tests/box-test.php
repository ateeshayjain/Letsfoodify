<?php
/**
 * Build your own box: when a box earns its discount, and how much.
 *
 * The rule worth defending by name: THE DISCOUNT EXISTS ONLY WHILE THE BOX IS
 * COMPLETE. Each pack is its own cart line; take one out in the cart and the
 * box is five packs, and a five-pack "box" at the six-pack price is a discount
 * the shop never offered. Second: the figure the builder promises is the
 * figure the cart charges — one rounding rule, down, in paise.
 *
 *   php tests/box-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../theme/foodify/inc/product-combo.php';
require __DIR__ . '/../theme/foodify/inc/box-builder.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}
function line( string $box, int $size, int $qty, float $total ): array {
	return [ 'box_id' => $box, 'size' => $size, 'qty' => $qty, 'total' => $total ];
}

// Test-only sizes. These are NOT the client's numbers (there are none yet).
$SIZES = [ 3 => 5, 6 => 10 ];

echo "── the option is read, never guessed ──\n";
check( 'unset -> no sizes, the feature is off', [] === foodify_box_sizes_parse( null ) && [] === foodify_box_sizes_parse( '' ) && [] === foodify_box_sizes_parse( [] ) );
check( 'what wp option --format=json stores (string keys) is read', [ 3 => 5, 6 => 10 ] === foodify_box_sizes_parse( [ '6' => 10, '3' => 5 ] ) );
check( 'a JSON string is read too, and sorted by size', [ 3 => 5, 9 => 12 ] === foodify_box_sizes_parse( '{"9":12,"3":5}' ) );
check( 'a percent typed as "10%" is dropped, not coerced', [] === foodify_box_sizes_parse( [ '6' => '10%' ] ) );
check( 'half a pack is not a size', [] === foodify_box_sizes_parse( [ '6.5' => 10 ] ) );
check( 'a box of one is a pack, not a box', [] === foodify_box_sizes_parse( [ '1' => 10 ] ) );
check( 'a fractional percent is dropped', [] === foodify_box_sizes_parse( [ '6' => 7.5 ] ) );
check( 'an absurd percent is dropped', [] === foodify_box_sizes_parse( [ '6' => 95 ] ) );
check( 'a 0% box is allowed (a convenience, not a discount)', [ 6 => 0 ] === foodify_box_sizes_parse( [ '6' => 0 ] ) );
check( 'one bad entry does not take the good ones with it', [ 6 => 10 ] === foodify_box_sizes_parse( [ '6' => 10, 'x' => 5 ] ) );
check( 'garbage JSON is off, not an error', [] === foodify_box_sizes_parse( '{6:' ) );

echo "── a complete box earns its discount ──\n";
$r = foodify_box_discount( [
	line( 'A', 6, 2, 350.0 ), line( 'A', 6, 3, 525.0 ), line( 'A', 6, 1, 175.0 ),
], $SIZES );
check( 'six packs in a box of six is complete', true === $r['boxes'][0]['complete'] && 6 === $r['boxes'][0]['packs'] );
check( 'the subtotal is the box lines summed', 1050.0 === $r['boxes'][0]['subtotal'] );
check( '10% of ₹1,050 is ₹105', 105 === $r['boxes'][0]['discount'] && 105 === $r['total'] );
check( 'and it reports nothing missing', 0 === $r['boxes'][0]['missing'] );

echo "── THE RULE: a broken box earns nothing ──\n";
// The customer removed one pack in the cart. Five packs at a six-pack price.
$r = foodify_box_discount( [ line( 'A', 6, 2, 350.0 ), line( 'A', 6, 3, 525.0 ) ], $SIZES );
check( 'five packs in a box of six is NOT complete', false === $r['boxes'][0]['complete'] );
check( 'so the discount is zero', 0 === $r['boxes'][0]['discount'] && 0 === $r['total'] );
check( 'and it says one is missing', 1 === $r['boxes'][0]['missing'] );

$r = foodify_box_discount( [ line( 'A', 6, 4, 700.0 ), line( 'A', 6, 3, 525.0 ) ], $SIZES );
check( 'seven packs (a quantity raised in the cart) is not complete either', false === $r['boxes'][0]['complete'] && 0 === $r['total'] );
check( 'and missing goes negative', -1 === $r['boxes'][0]['missing'] );

// The shop withdrew the six-pack size after the box was built.
$r = foodify_box_discount( [ line( 'A', 6, 6, 1050.0 ) ], [ 3 => 5 ] );
check( 'a size no longer offered earns nothing', false === $r['boxes'][0]['complete'] && false === $r['boxes'][0]['offered'] && 0 === $r['total'] );
$r = foodify_box_discount( [ line( 'A', 6, 6, 1050.0 ) ], [] );
check( 'the option cleared -> every box earns nothing', 0 === $r['total'] );

// Two lines claiming the same box with different sizes were not made by the builder.
$r = foodify_box_discount( [ line( 'A', 3, 3, 525.0 ), line( 'A', 6, 3, 525.0 ) ], $SIZES );
check( 'lines that disagree about the size are never complete', false === $r['boxes'][0]['complete'] && 0 === $r['total'] );

echo "── two boxes, and lines that are not boxes ──\n";
$r = foodify_box_discount( [
	line( 'A', 3, 3, 525.0 ), line( 'B', 6, 6, 1050.0 ), line( '', 0, 2, 350.0 ), line( 'C', 3, 0, 0.0 ),
], $SIZES );
check( 'each box is decided on its own', 2 === count( $r['boxes'] ) && 26 === $r['boxes'][0]['discount'] && 105 === $r['boxes'][1]['discount'] );
check( 'the total is the sum', 131 === $r['total'] );
check( 'a line with no box id is not a box', ! in_array( '', array_column( $r['boxes'], 'box_id' ), true ) );
check( 'boxes keep the order they appear in', [ 'A', 'B' ] === array_column( $r['boxes'], 'box_id' ) );

echo "── rounding: DOWN, in paise, and the page agrees with the cart ──\n";
check( '5% of ₹525 is ₹26.25 -> ₹26, never ₹27', 26 === foodify_box_percent_of( 525.0, 5 ) );
check( 'floating point does not decide it (₹0.10 + ₹0.20 class)', 1 === foodify_box_percent_of( 0.1 + 0.2 + 9.7, 10 ) );
check( '12% of ₹1,574.99 is ₹188.99 -> ₹188', 188 === foodify_box_percent_of( 1574.99, 12 ) );
check( 'nothing of nothing', 0 === foodify_box_percent_of( 0.0, 10 ) && 0 === foodify_box_percent_of( 500.0, 0 ) );
// The builder's promise is foodify_box_saving_line(); the cart's charge is foodify_box_discount().
$promise = foodify_box_saving_line( 525.0, 5, true );
$charged = foodify_box_discount( [ line( 'A', 3, 3, 525.0 ) ], $SIZES )['total'];
check( 'the promise and the charge are the same number', 'Save ₹26 (5% off)' === $promise && 26 === $charged );
$js = foodify_box_script();
check( 'the script rounds the same way (paise, floor)', str_contains( $js, 'Math.floor(Math.round(amount*100)*pct/10000)' ) );
// After a coupon the line totals are lower, and so is the box discount: never more than payable.
$r = foodify_box_discount( [ line( 'A', 3, 3, 10.0 ) ], [ 3 => 90 ] );
check( 'a discount can never exceed what is being discounted', $r['total'] <= 10 );

echo "── the fee lines ──\n";
$fees = foodify_box_fee_lines( foodify_box_discount( [ line( 'A', 6, 6, 1050.0 ) ], $SIZES ) );
check( 'one box -> one fee, with the brief\'s exact label', [ [ 'label' => 'Your box (6 packs) — 10% off', 'amount' => 105 ] ] === $fees );
// WooCommerce keys a fee by its name. Two identically named fees collide and
// the second is dropped — two boxes bought, one saving given.
$fees = foodify_box_fee_lines( foodify_box_discount( [ line( 'A', 6, 6, 1050.0 ), line( 'B', 6, 6, 900.0 ) ], $SIZES ) );
check( 'two boxes of one size are ONE fee line carrying both savings', 1 === count( $fees ) && 195 === $fees[0]['amount'] );
check( 'and the label says two', 'Your 2 boxes (6 packs each) — 10% off' === $fees[0]['label'] );
$fees = foodify_box_fee_lines( foodify_box_discount( [ line( 'A', 6, 5, 875.0 ) ], $SIZES ) );
check( 'a broken box adds no fee line at all', [] === $fees );
$fees = foodify_box_fee_lines( foodify_box_discount( [ line( 'A', 6, 6, 1050.0 ) ], [ 6 => 0 ] ) );
check( 'a 0% box adds no ₹0 fee line', [] === $fees );
$labels = array_column( foodify_box_fee_lines( foodify_box_discount( [ line( 'A', 6, 6, 1050.0 ), line( 'B', 3, 3, 525.0 ) ], $SIZES ) ), 'label' );
check( 'different sizes get different (non-colliding) labels', 2 === count( array_unique( $labels ) ) );

echo "── the cart says what broke, and how to fix it ──\n";
$box = foodify_box_discount( [ line( 'A', 6, 5, 875.0 ) ], $SIZES )['boxes'][0];
$msg = foodify_box_broken_notice( $box, foodify_box_item_label( 1, 1, 6 ) );
check( 'a short box is named with what it lost', str_contains( $msg, 'Your 6-pack box has 5 of 6 packs' ) && str_contains( $msg, '10% off is not applied' ) );
check( 'and the way back', str_contains( $msg, 'Add 1 more to any pack marked "6-pack box"' ) );
$box = foodify_box_discount( [ line( 'A', 6, 8, 1400.0 ) ], $SIZES )['boxes'][0];
check( 'an over-full box says how many to take out', str_contains( foodify_box_broken_notice( $box ), 'Take 2 out' ) );
$box = foodify_box_discount( [ line( 'A', 6, 6, 1050.0 ) ], $SIZES )['boxes'][0];
check( 'a complete box says nothing', '' === foodify_box_broken_notice( $box ) );
$box = foodify_box_discount( [ line( 'A', 6, 6, 1050.0 ) ], [ 3 => 5 ] )['boxes'][0];
check( 'a withdrawn size says it is priced separately', str_contains( foodify_box_broken_notice( $box ), 'priced separately' ) );
check( 'with two boxes, lines say which box', 'Box 2 · 6 packs' === foodify_box_item_label( 2, 2, 6 ) && '6-pack box' === foodify_box_item_label( 1, 1, 6 ) );

echo "── single packs only, in stock only ──\n";
$pack = [ 'type' => 'simple', 'purchasable' => true, 'in_stock' => true, 'is_combo' => false, 'price' => 175.0, 'visible' => true ];
check( 'an in-stock single pack is eligible', foodify_box_eligible( $pack ) );
check( 'a combo is not (a box of boxes double-discounts)', ! foodify_box_eligible( [ 'is_combo' => true ] + $pack ) );
check( 'a sold-out pack is not', ! foodify_box_eligible( [ 'in_stock' => false ] + $pack ) );
check( 'a variable product is not (no variant picker)', ! foodify_box_eligible( [ 'type' => 'variable' ] + $pack ) );
check( 'an unpriced pack is not', ! foodify_box_eligible( [ 'price' => 0.0 ] + $pack ) );
check( 'a hidden product is not', ! foodify_box_eligible( [ 'visible' => false ] + $pack ) );

echo "── the submitted box is re-decided, never trusted ──\n";
$cat = [ 11 => [ 'name' => 'Express Dal Fry', 'max' => 6 ], 12 => [ 'name' => 'Idli Sambhar', 'max' => 2 ] ];
$v = foodify_box_validate( [ '11' => '4', '12' => '2' ], 6, $SIZES, $cat );
check( 'an exact box is accepted', $v['ok'] && [ 11 => 4, 12 => 2 ] === $v['packs'] );
$v = foodify_box_validate( [ '11' => '4' ], 6, $SIZES, $cat );
check( 'short -> refused, saying how many more', ! $v['ok'] && str_contains( $v['error'], 'Add 2 more' ) );
$v = foodify_box_validate( [ '11' => '5', '12' => '2' ], 6, $SIZES, $cat );
check( 'over -> refused, saying how many to take out', ! $v['ok'] && str_contains( $v['error'], 'Take 1 out' ) );
$v = foodify_box_validate( [ '11' => '3', '12' => '3' ], 6, $SIZES, $cat );
check( 'more than are in stock -> refused, naming the pack', ! $v['ok'] && str_contains( $v['error'], 'Only 2 of Idli Sambhar' ) );
$v = foodify_box_validate( [ '11' => '3', '99' => '3' ], 6, $SIZES, $cat );
check( 'a pack not in the live catalogue (sold out, a combo, forged) -> refused', ! $v['ok'] );
$v = foodify_box_validate( [ '11' => '6' ], 5, $SIZES, $cat );
check( 'a size the shop does not offer -> refused', ! $v['ok'] );
$v = foodify_box_validate( [ '11' => '6' ], 6, [], $cat );
check( 'no sizes set -> nothing can be sold', ! $v['ok'] && str_contains( $v['error'], 'not available' ) );
$v = foodify_box_validate( [ '11' => '-6' ], 6, $SIZES, $cat );
check( 'a negative quantity is refused', ! $v['ok'] );
$v = foodify_box_validate( [ '11' => '2.5', '12' => '2' ], 6, $SIZES, $cat );
check( 'a fractional quantity is refused', ! $v['ok'] );
$v = foodify_box_validate( [ '11' => '6', '12' => '0', '13' => '' ], 6, $SIZES, $cat );
check( 'zeros and blanks are simply not chosen', $v['ok'] && [ 11 => 6 ] === $v['packs'] );
check( 'a box id is short, random and safe in markup', (bool) preg_match( '/^[0-9A-F]{8}$/', foodify_box_new_id() ) && foodify_box_new_id() !== foodify_box_new_id() );

echo "── free delivery is judged before the box discount (decision 4, BUY-1) ──\n";
// The review's case: Rajma ×2 + Idli = ₹615 qualifies for free delivery at ₹599.
// As a 3-pack box the cart takes ₹30 off. Judged after the box, ₹585 < ₹599 and
// ₹59 delivery is added: ₹644 for the box against ₹615 for the same packs loose.
$loose  = 615.0;
$boxed  = foodify_box_discount( [ line( 'R', 3, 2, 410.0 ), line( 'R', 3, 1, 205.0 ) ], [ 3 => 5 ] );
check( 'the 3-pack box earns ₹30 (5% of ₹615, down)', 30 === $boxed['total'] );
$basis  = foodify_box_free_delivery_basis( $loose, $boxed['total'] );
check( 'the free-delivery figure ignores the box discount: ₹615, not ₹585', 615.0 === $basis );
$ship   = static fn( float $b ): float => $b >= 599.0 ? 0.0 : 59.0;   // the test minimum and charge, not the client's
check( 'so the box still has free delivery', 0.0 === $ship( $basis ) );
check( 'and the boxed order (₹585) costs LESS than the loose one (₹615), never more', $loose - $boxed['total'] + $ship( $basis ) < $loose + $ship( $loose ) );
check( 'a box never moves a qualifying cart below the minimum, at any discount', (function (): bool {
	foreach ( [ 599.0, 600.0, 615.0, 650.0, 1000.0 ] as $b ) {
		foreach ( [ 1, 30, 60, 90, 200 ] as $d ) {
			if ( foodify_box_free_delivery_basis( $b, $d ) < 599.0 ) { return false; }
		}
	}
	return true;
})() );
check( 'a cart below the minimum stays below it (the box adds nothing either way)', 585.0 === foodify_box_free_delivery_basis( 585.0, 0 ) );
$src = (string) file_get_contents( __DIR__ . '/../theme/foodify/inc/box-builder.php' );
check( 'box-builder.php registers no free-shipping filter that could re-apply the box', ! preg_match( "/add_filter\(\s*'woocommerce_shipping_free_shipping_is_available'/", $src ) );
check( 'the old after-the-box rule is gone', ! str_contains( $src, 'function foodify_box_free_shipping_holds' ) );
$sc = (string) file_get_contents( __DIR__ . '/../theme/foodify/inc/shortcodes.php' );
preg_match( '/function foodify_shipping_comparison_subtotal\(\).*?\n}/s', $sc, $m );
$cmp = $m[0] ?? '';
check( 'the progress bar asks the same rule for its figure', str_contains( $cmp, 'foodify_box_free_delivery_basis(' ) );
check( 'the progress bar does not subtract the box discount itself', ! preg_match( '/-=\s*\(float\)\s*foodify_box_cart_discount_total/', $cmp ) && '' !== $cmp );

echo "── the builder page ──\n";
$products = [
	[ 'id' => 11, 'name' => 'Express Dal Fry', 'price' => 175.0, 'max' => 6, 'prep' => 'Ready in 6 minutes', 'image' => '/img/dal.jpg' ],
	[ 'id' => 12, 'name' => 'Idli <Sambhar>', 'price' => 150.0, 'max' => 2 ],
];
$off = foodify_box_builder_html( [], $products );
check( 'no sizes set -> "coming soon", and NOTHING can be submitted', str_contains( $off, 'coming soon' ) && ! str_contains( $off, '<form' ) && ! str_contains( $off, 'Add box to cart' ) );
$h = foodify_box_builder_html( $SIZES, $products, [ 'action' => '/build-a-box/', 'nonce' => 'abc' ] );
check( 'every size is offered with its percentage', str_contains( $h, 'value="3"' ) && str_contains( $h, '5% off' ) && str_contains( $h, '10% off' ) );
check( 'the form posts the nonce', str_contains( $h, 'name="_fdbox" value="abc"' ) );
check( 'a stepper per pack, capped at what can be sold', str_contains( $h, 'name="box_pack[12]"' ) && str_contains( $h, 'max="2"' ) && str_contains( $h, 'data-max="2"' ) );
check( 'names are escaped', str_contains( $h, 'Idli &lt;Sambhar&gt;' ) && ! str_contains( $h, '<Sambhar>' ) );
check( 'before a size is chosen it says so', str_contains( $h, 'Choose a box size to start.' ) );
check( 'the sizes the script reads are the sizes offered', str_contains( $h, 'data-sizes="{&quot;3&quot;:5,&quot;6&quot;:10}"' ) );
check( 'steppers have names a screen reader can say', str_contains( $h, 'aria-label="One more Express Dal Fry"' ) );
check( 'the status line is announced', str_contains( $h, 'data-fd-box-status aria-live="polite"' ) );
$h = foodify_box_builder_html( $SIZES, $products, [ 'size' => 3, 'selected' => [ 11 => 3 ] ] );
check( 'a refused submission keeps the choice, and the page totals it', str_contains( $h, 'value="3" checked' ) && str_contains( $h, '3 of 3 packs · your box is full' ) );
check( 'with the saving rounded down and the total after it', str_contains( $h, 'Save ₹26 (5% off)' ) && str_contains( $h, '₹499' ) );
check( 'and the per-meal figure from the combo rule (₹499 / 3 = ₹166, down)', str_contains( $h, '₹166 per meal' ) );
$h = foodify_box_builder_html( $SIZES, $products, [ 'size' => 6, 'selected' => [ 11 => 2, 12 => 9 ] ] );
check( 'a selection over the stock cap is clamped, not trusted', str_contains( $h, '4 of 6 packs · 2 more to fill' ) );
check( 'everything sold out -> says so, and nothing can be submitted', ! str_contains( foodify_box_builder_html( $SIZES, [] ), '<form' ) );
check( 'a javascript: link is never printed', ! str_contains( foodify_box_builder_html( [], [], [ 'combos_url' => 'javascript:alert(1)' ] ), 'javascript' ) );

echo "── the page never promises more than the cart gives (BUY-4) ──\n";
// The review's case: a 6-pack box worth ₹1,160 at 10% off, NALIN10 (10%) already in the cart.
// The cart takes the box % after the coupon: 10% of ₹1,044 = ₹104. The page said ₹116.
$cart = foodify_box_discount( [ line( 'N', 6, 6, 1160.0 * 0.9 ) ], [ 6 => 10 ] );
check( 'the cart gives ₹104', 104 === $cart['total'] );
check( 'the page promises ₹104 too, not ₹116', 104 === foodify_box_promised_saving( 1160.0, 10, 6, [ 'pct' => 10.0 ] ) );
check( 'and says the saving is after the code', 'Save ₹104 (10% off, after your code)' === foodify_box_saving_line( 1160.0, 10, true, [ 'pct' => 10.0 ], 6 ) );
check( 'no coupon -> the plain promise, unchanged', 'Save ₹116 (10% off)' === foodify_box_saving_line( 1160.0, 10, true ) && 116 === foodify_box_promised_saving( 1160.0, 10, 6, [] ) );
check( 'a ₹100 fixed-cart code -> at most ₹100 off the box lines -> ₹106, never more', 106 === foodify_box_promised_saving( 1160.0, 10, 6, [ 'fixed' => 100.0 ] ) );
check( 'a ₹10-per-item code -> at most ₹60 off six packs -> ₹110', 110 === foodify_box_promised_saving( 1160.0, 10, 6, [ 'each' => 10.0 ] ) );
check( 'a coupon type with no known bound -> no rupee figure at all', null === foodify_box_promised_saving( 1160.0, 10, 6, [ 'unknown' => true ] ) && '10% off, after your code' === foodify_box_saving_line( 1160.0, 10, true, [ 'unknown' => true ], 6 ) );
check( 'a 100% code -> nothing left to discount -> ₹0, never negative', 0 === foodify_box_promised_saving( 1160.0, 10, 6, [ 'pct' => 150.0 ] ) );
// Across many baskets and codes, the promise is never above what the cart charges.
$never_more = true;
foreach ( [ 525.0, 615.0, 999.5, 1160.0, 2345.75 ] as $sub ) {
	foreach ( [ 0.0, 5.0, 10.0, 25.0 ] as $cpct ) {
		foreach ( [ 3 => 5, 6 => 10 ] as $n => $bp ) {
			$given = foodify_box_discount( [ line( 'X', $n, $n, round( $sub * ( 1 - $cpct / 100 ), 2 ) ) ], [ $n => $bp ] )['total'];
			if ( foodify_box_promised_saving( $sub, $bp, $n, [ 'pct' => $cpct ] ) > $given ) { $never_more = false; }
		}
	}
}
check( 'over 40 baskets × codes, the page never promises more than the cart gives', $never_more );
$h = foodify_box_builder_html( $SIZES, $products, [ 'size' => 3, 'selected' => [ 11 => 3 ], 'coupons' => [ 'pct' => 10.0, 'fixed' => 0.0, 'each' => 0.0, 'unknown' => false ] ] );
check( 'the page carries the cart\'s coupons for the script', str_contains( $h, 'data-coupon-pct="10"' ) && str_contains( $h, 'data-coupon-fixed="0"' ) );
check( 'and renders the after-code promise (5% of ₹472 = ₹23)', str_contains( $h, 'Save ₹23 (5% off, after your code)' ) );
check( 'no coupon -> no coupon attributes', ! str_contains( foodify_box_builder_html( $SIZES, $products ), 'data-coupon-' ) );
$js = foodify_box_script();
check( 'the script reads the same bound', str_contains( $js, "data-coupon-pct" ) && str_contains( $js, 'Math.floor(Math.max(0,sub*(1-cp.pct/100)-cp.fixed-cp.each*n))' ) );

echo "── no size chosen: step 2 is closed, and the status leads to step 1 (BUY-10) ──\n";
$h = foodify_box_builder_html( $SIZES, $products );
check( 'the status is a link to step 1', str_contains( $h, '<a class="fd-box__status-go" href="#fd-box-sizes" data-fd-box-go>Choose a box size to start.</a>' ) && str_contains( $h, 'id="fd-box-sizes"' ) );
check( 'once a size is chosen it is plain status text', ! str_contains( foodify_box_builder_html( $SIZES, $products, [ 'size' => 3 ] ), 'data-fd-box-go' ) );
check( 'the script disables the quantity fields until a size is chosen', str_contains( $js, "p.querySelector('.fd-box__qty').disabled=s<1" ) );
check( 'and the minus buttons', str_contains( $js, "p.querySelector('.fd-box__minus').disabled=s<1||q<1" ) );
check( 'the link focuses the first size', str_contains( $js, 'if(radios[0])radios[0].focus();' ) );
check( 'submitting with no size goes to step 1', str_contains( $js, 'if(s<1){ev.preventDefault();toStep1();return;}' ) );

echo "── status copy, word for word with the script ──\n";
check( 'short', '4 of 6 packs · 2 more to fill' === foodify_box_status_line( 6, 4 ) );
check( 'full', '6 of 6 packs · your box is full' === foodify_box_status_line( 6, 6 ) );
check( 'over', '7 of 6 packs · take 1 out to fit the box' === foodify_box_status_line( 6, 7 ) );
check( 'the script carries the same three phrases', str_contains( $js, "' more to fill'" ) && str_contains( $js, "your box is full" ) && str_contains( $js, "' out to fit the box'" ) );
check( 'before full, the saving is a promise with a condition', '10% off once your box is full' === foodify_box_saving_line( 875.0, 10, false ) );

echo "── the script ──\n";
check( 'it cannot end the script block early', ! str_contains( $js, '</script' ) );
check( 'it is inert without the markup', str_contains( $js, "querySelector('[data-fd-box]');if(!root)return" ) );
check( 'the button waits for a full box', str_contains( $js, 'out.add.disabled=!full' ) );

echo "── the page at 360px ──\n";
$css = (string) file_get_contents( __DIR__ . '/../theme/foodify/style.css' );
preg_match( '#/\* ==== LANE C:.*?==== END LANE C ==== \*/#s', $css, $m );
$lane = $m[0] ?? '';
check( 'lane C has CSS', strlen( $lane ) > 200 );
check( 'steppers and size chips are tap-target high', substr_count( $lane, 'var(--wp--custom--tap-target)' ) >= 3 );
check( 'the summary bar is sticky at the bottom on phones', (bool) preg_match( '/\.fd-box__bar\s*\{[^}]*position:\s*sticky[^}]*bottom:\s*0/s', $lane ) );
check( 'no hardcoded px font size or spacing in lane C', ! preg_match( '/(font-size|padding|margin|gap)\s*:[^;]*\d+px/', $lane ) );
$tpl = (string) file_get_contents( __DIR__ . '/../theme/foodify/templates/page-build-a-box.html' );
check( 'the /build-a-box/ template renders the builder', str_contains( $tpl, '[foodify_box_builder]' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
