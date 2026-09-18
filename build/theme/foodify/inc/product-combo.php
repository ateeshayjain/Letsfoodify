<?php
/**
 * Combos — a pack of several meals sold as one product.
 *
 * WHY THIS EXISTS. "Combos" has been a category and a navigation link since the
 * first build, and nothing in the theme knew what a combo was: a three-meal
 * pack rendered exactly like a single sachet — one price, one prep time, no
 * mention of what is inside it. That is the one product type where the buyer's
 * first question is not "what is it?" but "what do I get?", and the answer was
 * nowhere on the page.
 *
 * THE RULES, and they are the same shape as the SAVE badge on a single product:
 *
 *  1. A SAVING IS SHOWN ONLY WHEN IT IS REAL AND STATED. It is computed from a
 *     figure the shop enters — what the same items cost bought separately —
 *     never guessed from the pack price. No figure, no saving line: a combo
 *     that is not cheaper is still a convenience, and inventing a discount to
 *     decorate it is the fabricated-trust pattern this rebuild exists to remove.
 *
 *  2. WHAT IS INSIDE IS A LIST, NOT A SENTENCE. One line per item, with an
 *     optional count, so the card can say "3 meals" and the page can show the
 *     items — and so the same field can one day feed a grouped product without
 *     re-typing.
 *
 *  3. THE PER-MEAL PRICE IS ARITHMETIC, NOT MARKETING. ₹525 for three meals is
 *     ₹175 a meal; it is printed because it is the number a buyer works out in
 *     their head anyway, and it is rounded DOWN so it can never overstate the
 *     value.
 *
 * @package Foodify
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parse the "what's inside" field into items.
 *
 * One item per line. A count may lead ("2 × Dal Fry", "2x Dal Fry") or trail
 * ("Dal Fry × 2"); both are how a shop owner actually types it. No count means
 * one. A line with no name is not an item.
 *
 * @param string $raw
 * @return array<int,array{name:string,qty:int}>
 */
function foodify_combo_items( string $raw ): array {
	$items = [];
	foreach ( preg_split( '/\R/', $raw ) ?: [] as $line ) {
		$line = trim( (string) $line );
		if ( '' === $line ) {
			continue;
		}
		$qty = 1;
		// Trailing count: "Dal Fry × 2" / "Dal Fry x2".
		if ( preg_match( '/^(.*?)\s*[x×]\s*(\d{1,2})$/iu', $line, $m ) ) {
			$line = trim( $m[1] );
			$qty  = (int) $m[2];
		// Leading count: "2 × Dal Fry" / "2x Dal Fry".
		} elseif ( preg_match( '/^(\d{1,2})\s*[x×]\s*(.+)$/iu', $line, $m ) ) {
			$qty  = (int) $m[1];
			$line = trim( $m[2] );
		}
		// A line that is only a count ("3 ×") is a typo, not an item: without
		// this it becomes a meal named "3 ×" on the customer's product page.
		// \p{L} rather than a-z, so a Devanagari name is still a name.
		if ( '' === $line || $qty < 1 || ! preg_match( '/\p{L}/u', $line ) ) {
			continue;
		}
		$items[] = [ 'name' => $line, 'qty' => $qty ];
	}
	return $items;
}

/**
 * How many meals the pack holds — the sum of the counts, not the line count.
 *
 * @param array<int,array{name:string,qty:int}> $items
 */
function foodify_combo_meal_count( array $items ): int {
	$n = 0;
	foreach ( $items as $item ) {
		$n += max( 1, (int) ( $item['qty'] ?? 1 ) );
	}
	return $n;
}

/** Price per meal, rounded DOWN so the figure can never flatter the pack. */
function foodify_combo_per_meal( float $price, int $meals ): int {
	if ( $price <= 0 || $meals < 1 ) {
		return 0;
	}
	return (int) floor( $price / $meals );
}

/**
 * The saving against buying the same items separately.
 *
 * @return array{amount:int,percent:int} Zeroes when there is nothing honest to claim.
 */
function foodify_combo_saving( float $separate, float $price ): array {
	if ( $separate <= 0 || $price <= 0 || $separate <= $price ) {
		return [ 'amount' => 0, 'percent' => 0 ];
	}
	$amount = (int) floor( $separate - $price );
	return [
		'amount'  => $amount,
		'percent' => (int) floor( ( $separate - $price ) / $separate * 100 ),
	];
}

/** The floor a saving must clear to be worth a badge. Below this it is noise. */
function foodify_combo_saving_min(): int {
	return 25;
}

/** "Save ₹90" — or nothing at all. */
function foodify_combo_saving_badge( float $separate, float $price ): string {
	$saving = foodify_combo_saving( $separate, $price );
	if ( $saving['amount'] < foodify_combo_saving_min() ) {
		return '';
	}
	return 'Save ₹' . number_format( $saving['amount'] );
}

/**
 * The card's second line: how many meals, and the first few by name.
 *
 * A card has room for about three names. The rest are counted rather than
 * truncated mid-word, because "Dal Fry, Idli Sam…" reads as a broken layout.
 */
function foodify_combo_card_line( array $items, int $show = 3 ): string {
	if ( ! $items ) {
		return '';
	}
	$meals = foodify_combo_meal_count( $items );
	$names = array_column( $items, 'name' );
	$head  = array_slice( $names, 0, $show );
	$rest  = count( $names ) - count( $head );
	$list  = implode( ', ', $head ) . ( $rest > 0 ? ' +' . $rest . ' more' : '' );
	return ( 1 === $meals ? '1 meal' : $meals . ' meals' ) . ' · ' . $list;
}

/**
 * The strip under a combo's price. A single pack answers in grams and servings;
 * a pack of six answers in meals and what one meal costs.
 *
 * @param array<int,array{name:string,qty:int}> $items
 * @return array<int,string>
 */
function foodify_combo_yield_parts( array $items, float $price, string $prep = '' ): array {
	if ( ! $items ) {
		return [];
	}
	$meals = foodify_combo_meal_count( $items );
	$parts = [ 1 === $meals ? '1 meal' : $meals . ' meals' ];
	$per   = foodify_combo_per_meal( $price, $meals );
	if ( $per > 0 ) {
		$parts[] = '₹' . number_format( $per ) . ' per meal';
	}
	if ( '' !== trim( $prep ) ) {
		$parts[] = trim( $prep );
	}
	return $parts;
}

/**
 * The "What's inside" panel for a combo's product page.
 *
 * @param array<int,array{name:string,qty:int}> $items
 * @param array{price?:float,separate?:float,prep?:string} $d
 */
function foodify_combo_panel_html( array $items, array $d = [] ): string {
	if ( ! $items ) {
		return '';
	}
	$e     = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES );
	$meals = foodify_combo_meal_count( $items );
	$price = (float) ( $d['price'] ?? 0 );
	$sep   = (float) ( $d['separate'] ?? 0 );

	$rows = '';
	foreach ( $items as $item ) {
		$qty   = max( 1, (int) ( $item['qty'] ?? 1 ) );
		$rows .= sprintf(
			'<li class="fd-combo__item"><span class="fd-combo__qty">%1$d</span><span>%2$s</span></li>',
			$qty,
			$e( (string) $item['name'] )
		);
	}

	$facts = [];
	$per   = foodify_combo_per_meal( $price, $meals );
	if ( $per > 0 ) {
		$facts[] = '₹' . number_format( $per ) . ' per meal';
	}
	$badge = foodify_combo_saving_badge( $sep, $price );
	if ( '' !== $badge ) {
		$facts[] = $badge . ' against ₹' . number_format( (int) $sep ) . ' bought separately';
	}
	if ( ! empty( $d['prep'] ) ) {
		$facts[] = $e( (string) $d['prep'] );
	}

	return '<section class="fd-combo">'
		. '<h2 class="fd-combo__title">What is inside</h2>'
		. '<ul class="fd-combo__list">' . $rows . '</ul>'
		. ( $facts ? '<p class="fd-combo__facts">' . implode( ' · ', $facts ) . '</p>' : '' )
		. '</section>';
}

/*
 * ---------------------------------------------------------------------------
 * The WordPress half. Everything above is pure and is what the tests and the
 * preview call; everything below only fetches a product's fields and hands them
 * over.
 * ---------------------------------------------------------------------------
 */

if ( ! function_exists( 'get_post_meta' ) ) {
	return;
}

/** A product's combo fields, or null when it is not a combo. */
function foodify_combo_data( WC_Product $product ): ?array {
	$raw = (string) get_post_meta( $product->get_id(), '_foodify_combo_items', true );
	$items = foodify_combo_items( $raw );
	if ( ! $items ) {
		return null;
	}
	return [
		'items'    => $items,
		'price'    => (float) $product->get_price(),
		'separate' => (float) get_post_meta( $product->get_id(), '_foodify_combo_separate', true ),
	];
}

// The strip under the price: meals and per-meal price, not grams.
add_filter( 'foodify_pdp_yield_parts', static function ( array $parts, WC_Product $product ): array {
	$combo = foodify_combo_data( $product );
	if ( ! $combo ) {
		return $parts;
	}
	$prep = foodify_minutes_label( (string) $product->get_meta( '_foodify_prep_minutes' ) );
	return foodify_combo_yield_parts( $combo['items'], $combo['price'], '' !== $prep ? 'Ready in ' . $prep : '' );
}, 10, 2 );

// The card's second line, in place of the single-meal yield line.
add_filter( 'foodify_card_yield_line', static function ( string $line, WC_Product $product ): string {
	$combo = foodify_combo_data( $product );
	return $combo ? foodify_combo_card_line( $combo['items'] ) : $line;
}, 10, 2 );

// "Save ₹90" beside the price of a combo, on the card and on the page.
add_filter( 'woocommerce_get_price_html', static function ( string $html, WC_Product $product ): string {
	$combo = foodify_combo_data( $product );
	if ( ! $combo ) {
		return $html;
	}
	$badge = foodify_combo_saving_badge( $combo['separate'], $combo['price'] );
	return '' === $badge ? $html : $html . ' <span class="fd-save">' . esc_html( $badge ) . '</span>';
}, 21, 2 );

// The panel, directly under the buy box — before the tabs, because it is the
// question a combo buyer asks first.
add_action( 'woocommerce_after_single_product_summary', static function (): void {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	$combo = foodify_combo_data( $product );
	if ( ! $combo ) {
		return;
	}
	echo foodify_combo_panel_html( $combo['items'], [
		'price'    => $combo['price'],
		'separate' => $combo['separate'],
	] ); // phpcs:ignore WordPress.Security.EscapeOutput — escaped in the builder.
}, 5 );
