<?php
/**
 * 8: build your own box.
 *
 * A /build-a-box/ page: choose a box size, fill it with single packs, see the
 * running total and the saving, and add the box to the cart. The combo
 * reviews already ask for it ("Would like to choose what goes in the box").
 *
 * THE CART MODEL
 * --------------
 * A box is not a product. Each pack goes into the cart as its OWN line, at its
 * own price, carrying cart item data `foodify_box` = { id, size }. The box
 * discount is then a negative cart fee — "Your box (6 packs) — 10% off" — that
 * exists only while the box is COMPLETE: exactly `size` packs share the id.
 *
 * Why lines and not a bundle product: stock is the packs' own stock, GST is the
 * packs' own rate (the basket mixes 5% and 12%), the courier manifest and the
 * GA4 items are the packs, and a refund of one pouch is a refund of one line.
 * A synthetic "box" product would need every one of those re-taught.
 *
 * Removing a pack in the cart (or changing a quantity) breaks the box, and the
 * fee disappears with a cart notice that says so and says how to get it back —
 * the same lines, one more pack in the box. Nothing is silently re-priced.
 *
 * THE ORDER OF DISCOUNTS (decided here, documented for payments.php and WP-09)
 * ------------------------------------------------------------------------
 *   1. Coupons — WooCommerce applies them to line items first.
 *   2. The box discount — a percentage of the box lines' totals AFTER coupons
 *      (gross, tax included, which is what the customer sees). It never
 *      discounts a rupee twice: a coupon's rupee is already gone when the box
 *      percentage is taken, and the discount can never exceed what is payable.
 *      It is a fee, so it does not inflate get_total_discount(), which is
 *      WP-09's partner revenue basis.
 *   3. The prepaid saving (payments.php) — flat rupees off for paying now. It
 *      reads get_cart_contents_total(), which does not see fees. With a FLAT
 *      amount that is immaterial; if the client ever switches to
 *      `prepaid_rate`, the eligible figure should subtract
 *      foodify_box_cart_discount_total() — flagged in the handoff, not changed
 *      here (not this lane's file).
 *   Free shipping is decided AFTER the discounts the method is told to count
 *   (ignore_discounts = no, set by bootstrap.sh). A fee is not a coupon, so
 *   WooCommerce's own check would not see the box discount; the filter at the
 *   bottom of this file applies it.
 *
 * ROUNDING. The discount is rounded DOWN to the whole rupee, in paise
 * arithmetic, by the same rule in PHP and in the builder's script, so the
 * figure the page promises is the figure the cart charges, and where rounding
 * could make them differ it is the promise that is the smaller.
 *
 * GST. The fee ships non-taxable, exactly like the prepaid saving and for the
 * same reason (payments.php): it over-declares GST by a few rupees rather than
 * under-declaring it. Same open question for the client's CA.
 *
 * NO BUSINESS VALUE IS INVENTED. Sizes and percentages come from the option
 * `foodify_box_sizes` (a JSON object, box size => percent off). Unset or
 * invalid: the page says boxes are coming soon, nothing can be added, and
 * wp-admin names the option.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Pure — tested in tests/box-test.php without WordPress.
 * ---------------------------------------------------------------------- */

const FOODIFY_BOX_OPTION   = 'foodify_box_sizes';
const FOODIFY_BOX_MIN_SIZE = 2;    // a box of one is a pack
const FOODIFY_BOX_MAX_SIZE = 24;   // engineering bound on a typed option, not a policy

/**
 * The offered box sizes, from the option as stored.
 *
 * Accepts what `wp option update … --format=json` stores (an array with string
 * keys) or a JSON string. Anything that is not a whole size within bounds with
 * a whole percentage 0–90 is DROPPED rather than coerced: "6.5" packs or "10%"
 * typed into wp-admin must not become a box the shop never meant to sell.
 * A 0% size is allowed — a box that is a convenience, not a discount.
 *
 * @param mixed $raw
 * @return array<int,int> size => percent off, ascending by size
 */
function foodify_box_sizes_parse( $raw ): array {
	if ( is_string( $raw ) ) {
		$decoded = json_decode( $raw, true );
		$raw     = is_array( $decoded ) ? $decoded : [];
	}
	if ( ! is_array( $raw ) ) {
		return [];
	}
	$sizes = [];
	foreach ( $raw as $size => $pct ) {
		$size = (string) $size;
		if ( ! preg_match( '/^\d{1,2}$/', $size ) ) {
			continue;
		}
		$n = (int) $size;
		if ( $n < FOODIFY_BOX_MIN_SIZE || $n > FOODIFY_BOX_MAX_SIZE ) {
			continue;
		}
		if ( is_int( $pct ) ) {
			$p = $pct;
		} elseif ( is_string( $pct ) && preg_match( '/^\d{1,2}$/', trim( $pct ) ) ) {
			$p = (int) trim( $pct );
		} elseif ( is_float( $pct ) && floor( $pct ) === $pct ) {
			$p = (int) $pct;
		} else {
			continue;
		}
		if ( $p < 0 || $p > 90 ) {
			continue;
		}
		$sizes[ $n ] = $p;
	}
	ksort( $sizes );
	return $sizes;
}

/**
 * A percentage of an amount, rounded DOWN to the whole rupee, in integer
 * paise so floating point never decides what a customer is charged. The
 * builder's script runs the identical arithmetic (see foodify_box_script()).
 */
function foodify_box_percent_of( float $amount, int $pct ): int {
	if ( $amount <= 0.0 || $pct <= 0 ) {
		return 0;
	}
	$paise = (int) round( $amount * 100 );
	return intdiv( $paise * $pct, 10000 );
}

/**
 * Every box in a cart, and what each is owed.
 *
 * @param array<int,array{box_id:string,size:int,qty:int,total:float}> $lines
 *        One entry per cart line that carries `foodify_box`. `total` is the
 *        line's gross after coupons (line_total + line_tax).
 * @param array<int,int> $sizes Offered sizes, from foodify_box_sizes_parse().
 * @return array{boxes:array<int,array{box_id:string,size:int,packs:int,complete:bool,
 *               subtotal:float,percent:int,discount:int,missing:int,offered:bool}>,total:int}
 *         `missing` is size − packs: positive = packs short, negative = too many.
 */
function foodify_box_discount( array $lines, array $sizes ): array {
	$boxes = [];
	foreach ( $lines as $line ) {
		$id  = trim( (string) ( $line['box_id'] ?? '' ) );
		$qty = (int) ( $line['qty'] ?? 0 );
		if ( '' === $id || $qty < 1 ) {
			continue;
		}
		$size = (int) ( $line['size'] ?? 0 );
		if ( ! isset( $boxes[ $id ] ) ) {
			$boxes[ $id ] = [ 'box_id' => $id, 'size' => $size, 'packs' => 0, 'subtotal' => 0.0, 'mixed' => false ];
		}
		// Every line of one box was added with one size. Lines that disagree
		// were not made by the builder, and are never a complete box.
		if ( $boxes[ $id ]['size'] !== $size ) {
			$boxes[ $id ]['mixed'] = true;
		}
		$boxes[ $id ]['packs']    += $qty;
		$boxes[ $id ]['subtotal'] += max( 0.0, (float) ( $line['total'] ?? 0 ) );
	}

	$out   = [];
	$total = 0;
	foreach ( $boxes as $b ) {
		$offered  = isset( $sizes[ $b['size'] ] );
		$percent  = $offered ? (int) $sizes[ $b['size'] ] : 0;
		$complete = $offered && ! $b['mixed'] && $b['packs'] === $b['size'];
		$subtotal = round( $b['subtotal'], 2 );
		$discount = $complete ? min( foodify_box_percent_of( $subtotal, $percent ), (int) floor( $subtotal ) ) : 0;
		$total   += $discount;
		$out[]    = [
			'box_id'   => $b['box_id'],
			'size'     => $b['size'],
			'packs'    => $b['packs'],
			'complete' => $complete,
			'subtotal' => $subtotal,
			'percent'  => $percent,
			'discount' => $discount,
			'missing'  => $b['size'] - $b['packs'],
			'offered'  => $offered,
		];
	}
	return [ 'boxes' => $out, 'total' => $total ];
}

/**
 * The fee lines for a cart. Boxes of one size are ONE line: WooCommerce keys a
 * fee by its name, so two "Your box (6 packs)" fees would collide and the
 * second would be dropped — two boxes bought, one saving given.
 *
 * @param array{boxes:array,total:int} $result From foodify_box_discount().
 * @return array<int,array{label:string,amount:int}> Amount is positive; the glue negates it.
 */
function foodify_box_fee_lines( array $result ): array {
	$groups = [];
	foreach ( (array) ( $result['boxes'] ?? [] ) as $b ) {
		if ( empty( $b['complete'] ) || (int) $b['discount'] <= 0 ) {
			continue;
		}
		$k = $b['size'] . ':' . $b['percent'];
		$groups[ $k ] ??= [ 'size' => (int) $b['size'], 'percent' => (int) $b['percent'], 'n' => 0, 'amount' => 0 ];
		$groups[ $k ]['n']++;
		$groups[ $k ]['amount'] += (int) $b['discount'];
	}
	$lines = [];
	foreach ( $groups as $g ) {
		$lines[] = [
			'label'  => 1 === $g['n']
				? sprintf( 'Your box (%d packs) — %d%% off', $g['size'], $g['percent'] )
				: sprintf( 'Your %d boxes (%d packs each) — %d%% off', $g['n'], $g['size'], $g['percent'] ),
			'amount' => $g['amount'],
		];
	}
	return $lines;
}

/** "6-pack box", or "Box 2 · 6 packs" when the cart holds more than one box. */
function foodify_box_item_label( int $ordinal, int $boxes_in_cart, int $size ): string {
	return $boxes_in_cart > 1
		? sprintf( 'Box %d · %d packs', $ordinal, $size )
		: sprintf( '%d-pack box', $size );
}

/**
 * What the cart says about a box that is not complete. '' when there is
 * nothing to say (a complete box, or a 0% box whose breaking costs nothing).
 *
 * @param array  $box   One entry of foodify_box_discount()['boxes'].
 * @param string $label The label its lines carry (foodify_box_item_label()).
 */
function foodify_box_broken_notice( array $box, string $label = '' ): string {
	if ( ! empty( $box['complete'] ) ) {
		return '';
	}
	$size  = (int) ( $box['size'] ?? 0 );
	$packs = (int) ( $box['packs'] ?? 0 );
	$mark  = '' !== $label ? $label : sprintf( '%d-pack box', $size );
	$name  = str_starts_with( $mark, 'Box ' ) ? $mark : 'Your ' . $mark;
	if ( empty( $box['offered'] ) ) {
		return sprintf( '%s is not a box size we offer at the moment, so its packs are priced separately.', $name );
	}
	$pct = (int) ( $box['percent'] ?? 0 );
	if ( $pct <= 0 ) {
		return '';
	}
	if ( $packs < $size ) {
		return sprintf(
			'%1$s has %2$d of %3$d packs now, so its %4$d%% off is not applied. Add %5$d more to any pack marked "%6$s" to bring it back.',
			$name, $packs, $size, $pct, $size - $packs, $mark
		);
	}
	if ( $packs > $size ) {
		return sprintf(
			'%1$s has %2$d packs, but the box holds %3$d, so its %4$d%% off is not applied. Take %5$d out to bring it back.',
			$name, $packs, $size, $pct, $packs - $size
		);
	}
	return '';
}

/** The builder's status line. The script mirrors this word for word. */
function foodify_box_status_line( int $size, int $packs ): string {
	if ( $size < 1 ) {
		return 'Choose a box size to start.';
	}
	$of = sprintf( '%d of %d packs', $packs, $size );
	if ( $packs < $size ) {
		return $of . ' · ' . ( $size - $packs ) . ' more to fill';
	}
	if ( $packs > $size ) {
		return $of . ' · take ' . ( $packs - $size ) . ' out to fit the box';
	}
	return $of . ' · your box is full';
}

/** "Save ₹105 (10% off)" once full; "10% off once your box is full" before. */
function foodify_box_saving_line( float $subtotal, int $pct, bool $complete ): string {
	if ( $pct <= 0 ) {
		return '';
	}
	if ( ! $complete ) {
		return sprintf( '%d%% off once your box is full', $pct );
	}
	$save = foodify_box_percent_of( $subtotal, $pct );
	return $save > 0 ? sprintf( 'Save ₹%s (%d%% off)', number_format( $save ), $pct ) : '';
}

/** Whole rupees when whole, paise when not. (Below ₹1 lakh number_format and en-IN agree.) */
function foodify_box_rupees( float $amount ): string {
	return '₹' . number_format( $amount, floor( $amount ) === $amount ? 0 : 2 );
}

/**
 * May this product go into a box? Single packs only: not a combo (a box of
 * boxes double-discounts), not a variable product (the builder has no variant
 * picker), purchasable, in stock, priced, and visible in the catalogue.
 *
 * @param array{type?:string,purchasable?:bool,in_stock?:bool,is_combo?:bool,price?:float,visible?:bool} $p
 */
function foodify_box_eligible( array $p ): bool {
	return 'simple' === ( $p['type'] ?? '' )
		&& ! empty( $p['purchasable'] )
		&& ! empty( $p['in_stock'] )
		&& empty( $p['is_combo'] )
		&& (float) ( $p['price'] ?? 0 ) > 0.0
		&& (bool) ( $p['visible'] ?? true );
}

/**
 * Check a submitted box against what can be sold right now.
 *
 * The page may be minutes old: a pack can sell out, the shop can change the
 * sizes. So the request is re-decided against the live catalogue, never
 * trusted, and every refusal says what to do.
 *
 * @param array<mixed,mixed> $posted  pack id => quantity, as posted.
 * @param array<int,int>     $sizes
 * @param array<int,array{name:string,max:int}> $catalog Eligible packs only, keyed by id.
 * @return array{ok:bool,error:string,packs:array<int,int>}
 */
function foodify_box_validate( array $posted, int $size, array $sizes, array $catalog ): array {
	$no = static fn( string $why ): array => [ 'ok' => false, 'error' => $why, 'packs' => [] ];

	if ( ! $sizes ) {
		return $no( 'Build-your-own boxes are not available yet.' );
	}
	if ( ! isset( $sizes[ $size ] ) ) {
		return $no( 'Choose one of the box sizes on this page.' );
	}
	$packs = [];
	foreach ( $posted as $id => $qty ) {
		$id  = (int) $id;
		$raw = is_scalar( $qty ) ? trim( (string) $qty ) : '';
		if ( '' === $raw || preg_match( '/^0+$/', $raw ) ) {
			continue;
		}
		if ( ! preg_match( '/^\d{1,3}$/', $raw ) ) {
			return $no( 'Each pack needs a whole number.' );
		}
		if ( ! isset( $catalog[ $id ] ) ) {
			return $no( 'One of the packs you chose is no longer available — it may have just sold out. Please choose again.' );
		}
		$n   = (int) $raw;
		$max = (int) $catalog[ $id ]['max'];
		if ( $n > $max ) {
			return $no( sprintf( 'Only %1$d of %2$s can go in a box right now. Please choose %1$d or fewer.', $max, (string) $catalog[ $id ]['name'] ) );
		}
		$packs[ $id ] = $n;
	}
	$count = array_sum( $packs );
	if ( $count !== $size ) {
		return $no( $count < $size
			? sprintf( 'A box of %1$d needs %1$d packs — you chose %2$d. Add %3$d more.', $size, $count, $size - $count )
			: sprintf( 'A box of %1$d holds %1$d packs — you chose %2$d. Take %3$d out.', $size, $count, $count - $size ) );
	}
	return [ 'ok' => true, 'error' => '', 'packs' => $packs ];
}

/** A short random id for one box — unique within a cart, meaningless outside it. */
function foodify_box_new_id(): string {
	return strtoupper( bin2hex( random_bytes( 4 ) ) );
}

/**
 * Would free shipping still be earned once the box discount is counted?
 *
 * Only ever turns "available" into "not available": the filter passes
 * WooCommerce's own answer through untouched when it is already "no".
 *
 * @param float  $basis    What WooCommerce compared (subtotal − coupons; see shortcodes.php).
 * @param string $requires The free-shipping method's `requires` setting.
 */
function foodify_box_free_shipping_holds( float $basis, int $box_discount, float $min, string $requires, bool $has_free_coupon, bool $ignore_discounts ): bool {
	if ( $box_discount <= 0 || $ignore_discounts || $min <= 0.0 ) {
		return true;
	}
	if ( 'either' === $requires && $has_free_coupon ) {
		return true;   // the coupon earned it; the amount is irrelevant
	}
	if ( ! in_array( $requires, [ 'min_amount', 'either', 'both' ], true ) ) {
		return true;
	}
	return round( $basis - $box_discount, 2 ) >= $min;
}

/**
 * The builder.
 *
 * @param array<int,int> $sizes
 * @param array<int,array{id:int,name:string,price:float,image?:string,image_alt?:string,prep?:string,max:int}> $products
 * @param array{action?:string,nonce?:string,size?:int,selected?:array<int,int>,combos_url?:string,notices?:string} $ctx
 */
function foodify_box_builder_html( array $sizes, array $products, array $ctx = [] ): string {
	$e = static fn( $s ): string => htmlspecialchars( (string) $s, ENT_QUOTES );
	$u = static fn( $s ): string => preg_match( '#^(https?://|/)#', (string) $s ) ? htmlspecialchars( (string) $s, ENT_QUOTES ) : '';

	$notices = (string) ( $ctx['notices'] ?? '' );
	if ( ! $sizes ) {
		return foodify_box_unavailable_html( (string) ( $ctx['combos_url'] ?? '' ) );
	}
	if ( ! $products ) {
		$combos = $u( $ctx['combos_url'] ?? '' );
		return '<section class="fd-box fd-box--soon">' . $notices
			. '<p class="fd-box__soon">Every single pack is sold out right now, so a box cannot be filled. Please check back soon.</p>'
			. ( '' !== $combos ? '<p><a class="wp-element-button" href="' . $combos . '">See ready-made combos</a></p>' : '' )
			. '</section>';
	}

	$chosen   = (int) ( $ctx['size'] ?? 0 );
	$chosen   = isset( $sizes[ $chosen ] ) ? $chosen : 0;
	$selected = (array) ( $ctx['selected'] ?? [] );

	$size_html = '';
	foreach ( $sizes as $n => $pct ) {
		$size_html .= sprintf(
			'<label class="fd-box__size"><input type="radio" name="box_size" value="%1$d"%2$s required> <span class="fd-box__size-n">%1$d packs</span>%3$s</label>',
			$n,
			$n === $chosen ? ' checked' : '',
			$pct > 0 ? ' <span class="fd-box__size-off">' . $pct . '% off</span>' : ''
		);
	}

	$count = 0;
	$sub   = 0.0;
	$packs = '';
	foreach ( $products as $p ) {
		$id     = (int) $p['id'];
		$max    = max( 0, (int) $p['max'] );
		$qty    = min( $max, max( 0, (int) ( $selected[ $id ] ?? 0 ) ) );
		$count += $qty;
		$sub   += $qty * (float) $p['price'];
		$img    = $u( $p['image'] ?? '' );
		$packs .= sprintf(
			'<li class="fd-box__pack%9$s" data-id="%1$d" data-price="%2$s" data-max="%3$d">'
			. '%4$s<span class="fd-box__name">%5$s</span>%6$s<span class="fd-box__price">%7$s</span>'
			. '<span class="fd-box__stepper">'
			. '<button type="button" class="fd-box__minus" aria-label="One fewer %5$s">−</button>'
			. '<input type="number" class="fd-box__qty" id="fd-box-qty-%1$d" name="box_pack[%1$d]" value="%8$d" min="0" max="%3$d" step="1" inputmode="numeric" aria-label="%5$s in your box">'
			. '<button type="button" class="fd-box__plus" aria-label="One more %5$s">+</button>'
			. '</span></li>',
			$id,
			$e( (string) (float) $p['price'] ),
			$max,
			'' !== $img ? '<img class="fd-box__img" src="' . $img . '" alt="' . $e( $p['image_alt'] ?? '' ) . '" loading="lazy" decoding="async">' : '',
			$e( (string) $p['name'] ),
			! empty( $p['prep'] ) ? '<span class="fd-box__meta">' . $e( $p['prep'] ) . '</span>' : '',
			$e( foodify_box_rupees( (float) $p['price'] ) ),
			$qty,
			$qty > 0 ? ' is-in' : ''
		);
	}

	$pct      = $chosen ? (int) $sizes[ $chosen ] : 0;
	$complete = $chosen > 0 && $count === $chosen;
	$save     = $complete ? foodify_box_percent_of( $sub, $pct ) : 0;
	// The per-meal figure is the combo's rule — rounded down so it cannot flatter.
	$per      = ( $complete && function_exists( 'foodify_combo_per_meal' ) ) ? foodify_combo_per_meal( $sub - $save, $count ) : 0;

	return '<section class="fd-box" data-fd-box data-sizes="' . $e( (string) json_encode( (object) $sizes ) ) . '">'
		. $notices
		. '<form class="fd-box__form" method="post" action="' . $u( $ctx['action'] ?? '' ) . '">'
		. '<input type="hidden" name="foodify_box_add" value="1">'
		. '<input type="hidden" name="_fdbox" value="' . $e( $ctx['nonce'] ?? '' ) . '">'
		. '<fieldset class="fd-box__sizes"><legend class="fd-box__step">1 · Choose a box size</legend>' . $size_html . '</fieldset>'
		. '<fieldset class="fd-box__packs"><legend class="fd-box__step">2 · Fill it with single packs</legend>'
		. '<p class="fd-box__hint">Any mix, any number of each. The box discount is taken in your cart, after any coupon.</p>'
		. '<ul class="fd-box__grid">' . $packs . '</ul></fieldset>'
		. '<div class="fd-box__bar">'
		. '<p class="fd-box__status" data-fd-box-status aria-live="polite">' . $e( foodify_box_status_line( $chosen, $count ) ) . '</p>'
		. '<p class="fd-box__sum"><strong class="fd-box__total" data-fd-box-total>' . $e( foodify_box_rupees( $sub - $save ) ) . '</strong>'
		. ' <span class="fd-box__saving" data-fd-box-saving>' . $e( foodify_box_saving_line( $sub, $pct, $complete ) ) . '</span>'
		. ' <span class="fd-box__per" data-fd-box-per>' . ( $per > 0 ? $e( '₹' . number_format( $per ) . ' per meal' ) : '' ) . '</span></p>'
		. '<button type="submit" class="wp-element-button fd-box__add" data-fd-box-add>Add box to cart</button>'
		. '</div>'
		. '</form></section>';
}

/** Option unset: say so, sell nothing. */
function foodify_box_unavailable_html( string $combos_url = '' ): string {
	$link = preg_match( '#^(https?://|/)#', $combos_url )
		? '<p><a class="wp-element-button" href="' . htmlspecialchars( $combos_url, ENT_QUOTES ) . '">See ready-made combos</a></p>'
		: '';
	return '<section class="fd-box fd-box--soon"><p class="fd-box__soon">Build-your-own boxes are coming soon. Nothing on this page can be ordered yet.</p>' . $link . '</section>';
}

/**
 * The builder's script. Without it the page still works: type the numbers,
 * submit, and the server checks the box. With it: steppers, the running total,
 * the saving, "N more to fill", and the button waits until the box is full.
 *
 * Expects: [data-fd-box] with data-sizes (JSON size → percent), radios
 * input[name=box_size], .fd-box__pack[data-price][data-max] each holding
 * .fd-box__qty, .fd-box__minus, .fd-box__plus; and the outputs
 * [data-fd-box-status], [data-fd-box-total], [data-fd-box-saving],
 * [data-fd-box-per], [data-fd-box-add].
 *
 * Returned as a string so the clickable prototype embeds the SAME source.
 */
function foodify_box_script(): string {
	return <<<'JS'
(function(){
  var root=document.querySelector('[data-fd-box]');if(!root)return;
  var form=root.querySelector('form');if(!form)return;
  var sizes={};try{sizes=JSON.parse(root.getAttribute('data-sizes')||'{}');}catch(e){}
  var packs=[].slice.call(root.querySelectorAll('.fd-box__pack'));
  var radios=[].slice.call(root.querySelectorAll('input[name=box_size]'));
  var out={status:root.querySelector('[data-fd-box-status]'),total:root.querySelector('[data-fd-box-total]'),saving:root.querySelector('[data-fd-box-saving]'),per:root.querySelector('[data-fd-box-per]'),add:root.querySelector('[data-fd-box-add]')};
  function fmt(n){try{return new Intl.NumberFormat('en-IN',{maximumFractionDigits:2}).format(n);}catch(e){return String(n);}}
  function pctOf(amount,pct){if(amount<=0||pct<=0)return 0;return Math.floor(Math.round(amount*100)*pct/10000);}
  function qtyOf(p){var v=parseInt(p.querySelector('.fd-box__qty').value,10);return isNaN(v)||v<0?0:v;}
  function size(){for(var i=0;i<radios.length;i++){if(radios[i].checked)return parseInt(radios[i].value,10);}return 0;}
  function status(s,n){if(s<1)return 'Choose a box size to start.';var of=n+' of '+s+' packs';if(n<s)return of+' · '+(s-n)+' more to fill';if(n>s)return of+' · take '+(n-s)+' out to fit the box';return of+' · your box is full';}
  function count(){var n=0;packs.forEach(function(p){n+=qtyOf(p);});return n;}
  function update(){
    var s=size(),n=0,sub=0;packs.forEach(function(p){var q=qtyOf(p);n+=q;sub+=q*parseFloat(p.getAttribute('data-price')||'0');});
    var pct=s?parseInt(sizes[s]||0,10):0,full=s>0&&n===s,save=full?pctOf(sub,pct):0;
    out.status.textContent=status(s,n);
    out.total.textContent='₹'+fmt(Math.round((sub-save)*100)/100);
    out.saving.textContent=pct>0?(full?(save>0?'Save ₹'+fmt(save)+' ('+pct+'% off)':''):pct+'% off once your box is full'):'';
    out.per.textContent=full&&n>0?'₹'+fmt(Math.floor((sub-save)/n))+' per meal':'';
    out.add.disabled=!full;
    packs.forEach(function(p){var q=qtyOf(p),max=parseInt(p.getAttribute('data-max')||'0',10);
      p.querySelector('.fd-box__minus').disabled=q<1;
      p.querySelector('.fd-box__plus').disabled=s<1||n>=s||q>=max;
      p.classList.toggle('is-in',q>0);});
  }
  packs.forEach(function(p){
    var input=p.querySelector('.fd-box__qty'),max=parseInt(p.getAttribute('data-max')||'0',10);
    function set(v){input.value=String(Math.max(0,Math.min(max,v)));update();}
    p.querySelector('.fd-box__minus').addEventListener('click',function(){set(qtyOf(p)-1);});
    p.querySelector('.fd-box__plus').addEventListener('click',function(){set(qtyOf(p)+1);});
    input.addEventListener('input',update);input.addEventListener('change',function(){set(qtyOf(p));});
  });
  radios.forEach(function(r){r.addEventListener('change',update);});
  form.addEventListener('submit',function(ev){var s=size();if(s<1||count()!==s){ev.preventDefault();out.status.setAttribute('tabindex','-1');out.status.focus();}});
  update();
})();
JS;
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

/** The offered sizes, from the option. [] = the feature is off. */
function foodify_box_sizes(): array {
	return foodify_box_sizes_parse( get_option( FOODIFY_BOX_OPTION, [] ) );
}

/** The box lines in a cart, in the shape foodify_box_discount() reads. */
function foodify_box_cart_lines( $cart ): array {
	$lines = [];
	if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
		return $lines;
	}
	foreach ( $cart->get_cart() as $item ) {
		if ( empty( $item['foodify_box']['id'] ) ) {
			continue;
		}
		$lines[] = [
			'box_id' => (string) $item['foodify_box']['id'],
			'size'   => (int) ( $item['foodify_box']['size'] ?? 0 ),
			'qty'    => (int) ( $item['quantity'] ?? 0 ),
			// Gross after coupons: what the customer actually pays for the line.
			'total'  => (float) ( $item['line_total'] ?? 0 ) + (float) ( $item['line_tax'] ?? 0 ),
		];
	}
	return $lines;
}

/** The whole box discount on the live cart, in rupees — for payments.php / shortcodes.php to subtract. */
function foodify_box_cart_discount_total(): int {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return 0;
	}
	$r = foodify_box_discount( foodify_box_cart_lines( WC()->cart ), foodify_box_sizes() );
	return (int) $r['total'];
}

/** The eligible packs, as value arrays for the renderer and the validator, keyed by id. */
function foodify_box_catalog( int $max_size ): array {
	$out = [];
	if ( $max_size < 1 || ! function_exists( 'wc_get_products' ) ) {
		return $out;
	}
	$products = wc_get_products( [
		'status'       => 'publish',
		'type'         => 'simple',
		'stock_status' => 'instock',
		'limit'        => 200,
		'orderby'      => 'menu_order',
		'order'        => 'ASC',
	] );
	foreach ( $products as $product ) {
		if ( ! $product instanceof WC_Product ) {
			continue;
		}
		$price = (float) wc_get_price_to_display( $product );
		$ok    = foodify_box_eligible( [
			'type'        => $product->get_type(),
			'purchasable' => $product->is_purchasable(),
			'in_stock'    => $product->is_in_stock(),
			'is_combo'    => function_exists( 'foodify_combo_data' ) && null !== foodify_combo_data( $product ),
			'price'       => $price,
			'visible'     => $product->is_visible(),
		] );
		if ( ! $ok ) {
			continue;
		}
		$max = $max_size;
		if ( $product->managing_stock() && ! $product->backorders_allowed() ) {
			$max = min( $max, max( 0, (int) $product->get_stock_quantity() ) );
		}
		if ( $product->is_sold_individually() ) {
			$max = min( $max, 1 );
		}
		if ( $max < 1 ) {
			continue;
		}
		$image_id = (int) $product->get_image_id();
		$prep     = function_exists( 'foodify_minutes_label' ) ? foodify_minutes_label( (string) $product->get_meta( '_foodify_prep_minutes' ) ) : '';
		$out[ $product->get_id() ] = [
			'id'        => $product->get_id(),
			'name'      => $product->get_name(),
			'price'     => $price,
			'image'     => $image_id ? (string) wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '',
			'image_alt' => '',   // the name sits beside it; a repeated alt is noise to a screen reader
			'prep'      => '' !== $prep ? 'Ready in ' . $prep : '',
			'max'       => $max,
		];
	}
	return $out;
}

function foodify_box_page_url(): string {
	$page = get_page_by_path( 'build-a-box' );
	return $page ? (string) get_permalink( $page ) : home_url( '/build-a-box/' );
}

/** The form posts to the page itself. Decide, add, and go to the cart — or say why not. */
add_action( 'template_redirect', static function (): void {
	if ( ! is_page( 'build-a-box' ) ) {
		return;
	}
	// The page carries a nonce and live stock: a cached copy would be stale and broken.
	nocache_headers();

	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['foodify_box_add'] ) ) {
		return;
	}
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}
	$nonce = isset( $_POST['_fdbox'] ) ? sanitize_text_field( wp_unslash( $_POST['_fdbox'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'foodify_box_add' ) ) {
		wc_add_notice( esc_html__( 'This page had been open a while. Please choose your box again.', 'foodify' ), 'error' );
		return;
	}

	$sizes   = foodify_box_sizes();
	$size    = isset( $_POST['box_size'] ) ? absint( wp_unslash( $_POST['box_size'] ) ) : 0;
	$posted  = isset( $_POST['box_pack'] ) && is_array( $_POST['box_pack'] )
		? array_map( 'sanitize_text_field', wp_unslash( $_POST['box_pack'] ) )
		: [];
	$catalog = foodify_box_catalog( $sizes ? (int) max( array_keys( $sizes ) ) : 0 );
	$check   = foodify_box_validate( $posted, $size, $sizes, $catalog );
	if ( ! $check['ok'] ) {
		wc_add_notice( esc_html( $check['error'] ), 'error' );
		return;
	}

	$box   = [ 'id' => foodify_box_new_id(), 'size' => $size ];
	$added = [];
	foreach ( $check['packs'] as $product_id => $qty ) {
		$key = WC()->cart->add_to_cart( (int) $product_id, (int) $qty, 0, [], [ 'foodify_box' => $box ] );
		if ( ! $key ) {
			// All or nothing: half a box in the cart is a box that will never be complete.
			foreach ( $added as $k ) {
				WC()->cart->remove_cart_item( $k );
			}
			wc_add_notice( esc_html__( 'Your box could not be added — one of the packs may have just sold out. Please choose again.', 'foodify' ), 'error' );
			return;
		}
		$added[] = $key;
	}

	$msg = $sizes[ $size ] > 0
		/* translators: 1: number of packs, 2: percent off */
		? sprintf( __( 'Your box of %1$d packs is in the cart, with %2$d%% off while the box stays complete.', 'foodify' ), $size, $sizes[ $size ] )
		/* translators: %d: number of packs */
		: sprintf( __( 'Your box of %d packs is in the cart.', 'foodify' ), $size );
	wc_add_notice( esc_html( $msg ), 'success' );
	wp_safe_redirect( wc_get_cart_url() );
	exit;
}, 20 );

add_shortcode( 'foodify_box_builder', static function (): string {
	$GLOBALS['foodify_box_rendered'] = true;
	$sizes      = foodify_box_sizes();
	$combo      = get_term_by( 'slug', 'combos', 'product_cat' );
	$combos_url = $combo && ! is_wp_error( $combo ) ? (string) get_term_link( $combo ) : '';
	if ( ! $sizes ) {
		return foodify_box_unavailable_html( $combos_url );
	}

	// On a refused submission, keep what the customer chose.
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- display only; the write was nonce-checked.
	$selected = [];
	if ( ! empty( $_POST['box_pack'] ) && is_array( $_POST['box_pack'] ) ) {
		foreach ( wp_unslash( $_POST['box_pack'] ) as $id => $q ) {
			$selected[ absint( $id ) ] = absint( $q );
		}
	}
	$size = isset( $_POST['box_size'] ) ? absint( wp_unslash( $_POST['box_size'] ) ) : 0;
	// phpcs:enable

	return foodify_box_builder_html( $sizes, array_values( foodify_box_catalog( (int) max( array_keys( $sizes ) ) ) ), [
		'action'     => foodify_box_page_url(),
		'nonce'      => wp_create_nonce( 'foodify_box_add' ),
		'size'       => $size,
		'selected'   => $selected,
		'combos_url' => $combos_url,
		'notices'    => function_exists( 'wc_print_notices' ) ? (string) wc_print_notices( true ) : '',
	] );
} );

// The script, only on a page that rendered the builder.
add_action( 'wp_footer', static function (): void {
	if ( empty( $GLOBALS['foodify_box_rendered'] ) || ! foodify_box_sizes() ) {
		return;
	}
	echo '<script>' . foodify_box_script() . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- static script, no data.
} );

/** The fee — only for complete boxes. Before the prepaid saving (20). */
add_action( 'woocommerce_cart_calculate_fees', static function ( $cart ): void {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return;
	}
	if ( ! $cart instanceof WC_Cart ) {
		return;
	}
	$result = foodify_box_discount( foodify_box_cart_lines( $cart ), foodify_box_sizes() );
	foreach ( foodify_box_fee_lines( $result ) as $fee ) {
		$cart->add_fee( $fee['label'], -1 * (float) $fee['amount'], false );
	}
}, 10 );

/** A broken box is named in the cart, with the way back. */
add_action( 'woocommerce_check_cart_items', static function (): void {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}
	$result = foodify_box_discount( foodify_box_cart_lines( WC()->cart ), foodify_box_sizes() );
	$n      = count( $result['boxes'] );
	foreach ( $result['boxes'] as $i => $box ) {
		$msg = esc_html( foodify_box_broken_notice( $box, foodify_box_item_label( $i + 1, $n, (int) $box['size'] ) ) );
		if ( '' !== $msg && ! wc_has_notice( $msg, 'notice' ) ) {
			wc_add_notice( $msg, 'notice' );
		}
	}
} );

/** Which box a cart line belongs to, shown under the product name. */
add_filter( 'woocommerce_get_item_data', static function ( $data, $item ) {
	if ( empty( $item['foodify_box']['id'] ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
		return $data;
	}
	$ids = [];
	foreach ( WC()->cart->get_cart() as $line ) {
		if ( ! empty( $line['foodify_box']['id'] ) ) {
			$ids[ (string) $line['foodify_box']['id'] ] = true;
		}
	}
	$ordinal = array_search( (string) $item['foodify_box']['id'], array_keys( $ids ), true );
	$data    = is_array( $data ) ? $data : [];
	$data[]  = [
		'key'   => __( 'Box', 'foodify' ),
		'value' => esc_html( foodify_box_item_label( false === $ordinal ? 1 : (int) $ordinal + 1, count( $ids ), (int) $item['foodify_box']['size'] ) ),
	];
	return $data;
}, 10, 2 );

/** The order keeps the box on its lines, for the packer. */
add_action( 'woocommerce_checkout_create_order_line_item', static function ( $order_item, $cart_key, $values ): void {
	if ( empty( $values['foodify_box']['id'] ) || ! is_object( $order_item ) ) {
		return;
	}
	$id       = (string) $values['foodify_box']['id'];
	$size     = (int) $values['foodify_box']['size'];
	$complete = false;
	if ( function_exists( 'WC' ) && WC()->cart ) {
		foreach ( foodify_box_discount( foodify_box_cart_lines( WC()->cart ), foodify_box_sizes() )['boxes'] as $b ) {
			if ( $b['box_id'] === $id ) {
				$complete = $b['complete'];
			}
		}
	}
	$order_item->add_meta_data( '_foodify_box_id', $id, true );
	$order_item->add_meta_data( '_foodify_box_size', $size, true );
	$order_item->add_meta_data( '_foodify_box_complete', $complete ? 'yes' : 'no', true );
	// Visible to the shop and in the customer's emails: the packer groups by it.
	$order_item->add_meta_data( __( 'Box', 'foodify' ), sprintf( '%d-pack box %s', $size, $id ), true );
}, 10, 3 );

/** Free shipping is earned after the box discount, as it is after a coupon. */
add_filter( 'woocommerce_shipping_free_shipping_is_available', static function ( $available, $package, $method ) {
	if ( ! $available || ! is_object( $method ) || ! method_exists( $method, 'get_option' ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
		return $available;
	}
	$box = foodify_box_cart_discount_total();
	if ( $box <= 0 ) {
		return $available;
	}
	$has_coupon = false;
	foreach ( WC()->cart->get_coupons() as $coupon ) {
		if ( $coupon->get_free_shipping() ) {
			$has_coupon = true;
		}
	}
	$basis = function_exists( 'foodify_shipping_comparison_subtotal' )
		? foodify_shipping_comparison_subtotal()
		: (float) WC()->cart->get_displayed_subtotal();
	return foodify_box_free_shipping_holds(
		$basis,
		$box,
		(float) $method->get_option( 'min_amount', 0 ),
		(string) $method->get_option( 'requires', '' ),
		$has_coupon,
		'yes' === $method->get_option( 'ignore_discounts', 'no' )
	);
}, 20, 3 );

/** wp-admin names the option while the feature is off. */
add_action( 'admin_notices', static function (): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && ! in_array( $screen->id, [ 'dashboard', 'woocommerce_page_wc-settings', 'edit-product' ], true ) ) {
		return;
	}
	$raw = get_option( FOODIFY_BOX_OPTION, null );
	if ( foodify_box_sizes_parse( $raw ) ) {
		return;
	}
	$why = ( null === $raw || '' === $raw || [] === $raw )
		? __( 'is not set', 'foodify' )
		: __( 'is set, but no entry in it is a valid size and percentage', 'foodify' );
	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html( sprintf(
			/* translators: 1: option name, 2: reason */
			__( 'Build-a-box is switched off: the option %1$s %2$s, so /build-a-box/ tells customers boxes are coming soon. Set it to a JSON object of box size => percent off, with the numbers the client has confirmed.', 'foodify' ),
			FOODIFY_BOX_OPTION,
			$why
		) )
	);
} );
