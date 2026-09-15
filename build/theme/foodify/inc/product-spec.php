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
		'cooked_weight'=> [ 'label' => 'Makes (cooked)',     'group' => 'contents', 'required' => false ],
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
 * Wireframe 01–03 (design playbook §4, 2026-09-15): the yield line, the save
 * badge, the card badges, the tabbed body. All pure; all rendered by the
 * preview through PHP so the review page cannot drift from the theme.
 * ---------------------------------------------------------------------- */

/**
 * The yield line — "Net 80 g · Makes 260 g cooked · 2 servings · Ready in 6 min".
 * Kills the "80 g is nothing" objection before it forms. Parts nobody supplied
 * are omitted, never invented; a strip with one fact is still a strip.
 *
 * @param array<string,string> $v net_quantity, cooked_weight, servings, prep_minutes
 * @return array<int,string>
 */
function foodify_yield_parts( array $v ): array {
	$t = static fn( string $k ): string => trim( (string) ( $v[ $k ] ?? '' ) );
	$parts = [];
	if ( '' !== $t( 'net_quantity' ) ) {
		$parts[] = 'Net ' . $t( 'net_quantity' );
	}
	if ( '' !== $t( 'cooked_weight' ) ) {
		$parts[] = 'Makes ' . $t( 'cooked_weight' ) . ' cooked';
	}
	if ( '' !== $t( 'servings' ) ) {
		$n       = (int) $t( 'servings' );
		$parts[] = $n > 0 ? ( 1 === $n ? '1 serving' : $n . ' servings' ) : $t( 'servings' ) . ' servings';
	}
	if ( '' !== $t( 'prep_minutes' ) ) {
		$parts[] = 'Ready in ' . foodify_minutes_label( $t( 'prep_minutes' ) );
	}
	return $parts;
}

/** "6", "6 minutes", "6 min" -> "6 min". Keeps a non-numeric value as typed. */
function foodify_minutes_label( string $raw ): string {
	return preg_match( '/^\s*(\d+)/', $raw, $m ) ? $m[1] . ' min' : trim( $raw );
}

/** The card's second line: "Serves 2 · 6 min". Two questions, one line. */
function foodify_card_yield( array $v ): string {
	$bits = [];
	$s = (int) trim( (string) ( $v['servings'] ?? '' ) );
	if ( $s > 0 ) {
		$bits[] = 'Serves ' . $s;
	}
	$m = trim( (string) ( $v['prep_minutes'] ?? '' ) );
	if ( '' !== $m ) {
		$bits[] = foodify_minutes_label( $m );
	}
	return implode( ' · ', $bits );
}

/** Below this a SAVE badge reads as noise and teaches the eye that the strike is fake. */
function foodify_save_badge_min(): int {
	return 15;
}

function foodify_save_percent( float $regular, float $sale ): int {
	if ( $regular <= 0 || $sale <= 0 || $sale >= $regular ) {
		return 0;
	}
	return (int) floor( ( $regular - $sale ) / $regular * 100 );
}

/** 'Save 18%' at or above the floor; '' below it. Never rounds up to the floor. */
function foodify_save_badge( float $regular, float $sale ): string {
	$pct = foodify_save_percent( $regular, $sale );
	return $pct >= foodify_save_badge_min() ? 'Save ' . $pct . '%' : '';
}

/**
 * Card badges. At most two: left = commercial, right = dietary.
 * Sold out outranks everything — a bestseller you cannot buy is a sold-out
 * product. New outranks nothing.
 *
 * @param array{in_stock?:bool,bestseller?:bool,new?:bool,dietary?:array<int,string>} $s
 * @return array{commercial:string,dietary:string}
 */
function foodify_card_badges( array $s ): array {
	$commercial = '';
	if ( isset( $s['in_stock'] ) && ! $s['in_stock'] ) {
		$commercial = 'Sold out';
	} elseif ( ! empty( $s['bestseller'] ) ) {
		$commercial = 'Bestseller';
	} elseif ( ! empty( $s['new'] ) ) {
		$commercial = 'New';
	}
	$dietary = in_array( 'jain', array_map( 'strval', (array) ( $s['dietary'] ?? [] ) ), true ) ? 'Jain' : '';
	return [ 'commercial' => $commercial, 'dietary' => $dietary ];
}

function foodify_render_badges( array $badges ): string {
	$out = '';
	if ( '' !== $badges['commercial'] ) {
		$mod  = 'Sold out' === $badges['commercial'] ? 'fd-badge--out' : 'fd-badge--flame';
		$out .= '<span class="fd-badge ' . $mod . '">' . htmlspecialchars( $badges['commercial'], ENT_QUOTES ) . '</span>';
	}
	if ( '' !== $badges['dietary'] ) {
		$out .= '<span class="fd-badge fd-badge--leaf">' . htmlspecialchars( $badges['dietary'], ENT_QUOTES ) . '</span>';
	}
	return '' === $out ? '' : '<span class="fd-badges">' . $out . '</span>';
}

/** "Savoury, Mildly spicy, Tangy" -> at most four chips. */
function foodify_taste_chips( string $raw ): array {
	$chips = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ), static fn( $c ): bool => '' !== $c ) );
	return array_slice( $chips, 0, 4 );
}

/**
 * FAQ, typed as alternating "Q: …" / "A: …" lines. A question with no answer
 * is dropped — an unanswered FAQ is worse than none.
 *
 * @return array<int,array{q:string,a:string}>
 */
function foodify_faq_pairs( string $raw ): array {
	$pairs = [];
	$q     = null;
	foreach ( preg_split( '/\r?\n/', $raw ) as $line ) {
		$line = trim( $line );
		if ( preg_match( '/^q\s*[:.)-]\s*(.+)$/i', $line, $m ) ) {
			$q = $m[1];
		} elseif ( null !== $q && preg_match( '/^a\s*[:.)-]\s*(.+)$/i', $line, $m ) ) {
			$pairs[] = [ 'q' => $q, 'a' => $m[1] ];
			$q       = null;
		}
	}
	return $pairs;
}

/**
 * The tabbed body: About · Ingredients & nutrition · How to cook · FAQ.
 * Tabs on desktop, accordions on a phone, ONE content source. A section with
 * nothing in it is not a tab. Pack & label is deliberately NOT a tab — it is
 * rendered after them, always open: a legal declaration behind a collapsed
 * control is a declaration the regulator will say was hidden.
 *
 * @param array{about?:string,taste?:array,groups:array,nutrition:array,steps:array,faq?:array} $d
 * @return array<int,array{id:string,title:string,html:string}>
 */
function foodify_pdp_sections( array $d ): array {
	$e = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES );
	$sections = [];

	$about = trim( (string) ( $d['about'] ?? '' ) );
	$taste = (array) ( $d['taste'] ?? [] );
	if ( '' !== $about || $taste ) {
		$html = $about;   // post content: already filtered HTML from the_content
		if ( $taste ) {
			$html .= '<p class="fd-taste"><span class="fd-taste__label">Taste profile</span>'
				. implode( '', array_map( static fn( $c ): string => '<span class="fd-taste__chip">' . $e( (string) $c ) . '</span>', $taste ) )
				. '</p>';
		}
		$sections[] = [ 'id' => 'about', 'title' => 'About', 'html' => $html ];
	}

	$contents = $d['groups']['contents']['rows'] ?? [];
	if ( $contents || $d['nutrition'] ) {
		$html = $contents ? foodify_spec_rows_html( $contents ) : '';
		if ( $d['nutrition'] ) {
			$html .= '<h3 class="fd-spec__nutrition-title">Nutrition, per serving</h3><table class="fd-nutrition"><tbody>';
			foreach ( $d['nutrition'] as $row ) {
				$html .= sprintf( '<tr><th scope="row">%1$s</th><td>%2$s</td></tr>', $e( $row['label'] ), $e( $row['value'] ) );
			}
			$html .= '</tbody></table>';
		}
		$sections[] = [ 'id' => 'ingredients', 'title' => 'Ingredients & nutrition', 'html' => $html ];
	}

	if ( $d['steps'] ) {
		$html = '<ol class="fd-prep__steps">';
		foreach ( $d['steps'] as $step ) {
			$html .= sprintf(
				'<li class="fd-prep__step"><span class="fd-prep__n">%1$d</span><span class="fd-prep__body"><strong>%2$s</strong><span>%3$s</span></span></li>',
				(int) $step['n'],
				$e( $step['title'] ),
				$e( $step['detail'] )
			);
		}
		$sections[] = [ 'id' => 'cook', 'title' => 'How to cook', 'html' => $html . '</ol>' ];
	}

	$faq = (array) ( $d['faq'] ?? [] );
	if ( $faq ) {
		$html = '<dl class="fd-faq">';
		foreach ( $faq as $pair ) {
			$html .= '<div><dt>' . $e( (string) $pair['q'] ) . '</dt><dd>' . $e( (string) $pair['a'] ) . '</dd></div>';
		}
		$sections[] = [ 'id' => 'faq', 'title' => 'FAQ', 'html' => $html . '</dl>' ];
	}

	return $sections;
}

/**
 * One fact table, rendered once. Both tables on the page — what's in it, and
 * what the pack declares — are the same object with different rows, so they
 * are the same markup and the same .fd-spec__list rules in style.css. When
 * they were two copies, one of them silently lost its layout.
 *
 * @param array<int,array{label:string,value:string,provided:bool}> $rows
 */
function foodify_spec_rows_html( array $rows ): string {
	$e    = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES );
	$html = '<dl class="fd-spec__list">';
	foreach ( $rows as $row ) {
		$html .= sprintf(
			'<div%1$s><dt>%2$s</dt><dd>%3$s</dd></div>',
			$row['provided'] ? '' : ' class="is-missing"',
			$e( $row['label'] ),
			$e( $row['value'] )
		);
	}
	return $html . '</dl>';
}

/** The body markup: tab bar + panels, then the always-open label table. */
function foodify_render_pdp_body( array $d ): string {
	$e        = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES );
	$sections = foodify_pdp_sections( $d );
	$out      = '';

	if ( $sections ) {
		$out .= '<div class="fd-tabs" data-fd-tabs>';
		foreach ( $sections as $i => $s ) {
			$open = 0 === $i;
			$out .= sprintf(
				'<section class="fd-tab%1$s" id="fd-tab-%2$s">'
				. '<h2 class="fd-tab__title"><button type="button" class="fd-tab__button" aria-expanded="%3$s" aria-controls="fd-panel-%2$s" id="fd-tabbtn-%2$s">%4$s</button></h2>'
				. '<div class="fd-tab__panel" id="fd-panel-%2$s" role="region" aria-labelledby="fd-tabbtn-%2$s"%5$s>%6$s</div>'
				. '</section>',
				$open ? ' is-open' : '',
				$e( $s['id'] ),
				$open ? 'true' : 'false',
				$e( $s['title'] ),
				$open ? '' : ' hidden',
				$s['html']
			);
		}
		$out .= '</div>';
	}

	$label = $d['groups']['label']['rows'] ?? [];
	if ( $label ) {
		$out .= '<section class="fd-spec__group is-label fd-label"><h2>Pack &amp; label</h2>';
		$out .= foodify_spec_rows_html( $label );
		$out .= '<p class="fd-spec__note">These are the pack declarations. The same fields feed the Google product listing, so what you read here is what Google is told.</p></section>';
	}
	return $out;
}

/**
 * The page's one script: tabs on desktop (one panel open), accordions on a
 * phone (any number open), and the sticky add-to-cart bar that appears when
 * the real button leaves the viewport. Without it: every panel open, no bar.
 * Returned as a string so the preview embeds the SAME script.
 */
function foodify_pdp_script(): string {
	return <<<'JS'
(function(){
  var wide=matchMedia('(min-width:782px)');
  var tabs=document.querySelector('[data-fd-tabs]');
  if(tabs){
    var secs=[].slice.call(tabs.querySelectorAll('.fd-tab'));
    function set(sec,open){sec.classList.toggle('is-open',open);sec.querySelector('.fd-tab__button').setAttribute('aria-expanded',String(open));sec.querySelector('.fd-tab__panel').hidden=!open;}
    secs.forEach(function(sec){sec.querySelector('.fd-tab__button').addEventListener('click',function(){
      var isOpen=sec.classList.contains('is-open');
      if(wide.matches){secs.forEach(function(s){set(s,s===sec);});}
      else{set(sec,!isOpen);}
    });});
    wide.addEventListener('change',function(){if(wide.matches&&!secs.some(function(s){return s.classList.contains('is-open');})){set(secs[0],true);}});
  }
  var bar=document.querySelector('.fd-sticky-atc');var form=document.querySelector('form.cart');
  if(bar&&form&&'IntersectionObserver'in window){
    var qty=form.querySelector('input.qty');var unit=parseFloat(bar.getAttribute('data-unit')||'0');var total=bar.querySelector('.fd-sticky-atc__total');
    function fmt(n){try{return new Intl.NumberFormat('en-IN',{maximumFractionDigits:0}).format(n);}catch(e){return String(Math.round(n));}}
    function update(){var q=qty?parseInt(qty.value,10)||1:1;total.textContent='₹'+fmt(unit*q);bar.querySelector('.fd-sticky-atc__qty').textContent=q+(q===1?' pack':' packs');}
    if(qty){qty.addEventListener('input',update);qty.addEventListener('change',update);}update();
    new IntersectionObserver(function(en){bar.hidden=en[0].isIntersecting;},{threshold:0}).observe(form);
    bar.querySelector('button').addEventListener('click',function(){var b=form.querySelector('button[type=submit],.single_add_to_cart_button');if(b){b.click();}else{form.scrollIntoView({behavior:'smooth',block:'center'});}});
  }
})();
JS;
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
		'cooked_weight'=> $meta( 'cooked_weight' ),
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

/** Everything the body needs, from one product. */
function foodify_pdp_data( WC_Product $product ): array {
	$method = function_exists( 'foodify_prep_method' ) ? foodify_prep_method( $product ) : '';
	$meta   = static fn( string $k ): string => (string) $product->get_meta( '_foodify_' . $k );
	return [
		'about'     => (string) apply_filters( 'the_content', $product->get_description() ),
		'taste'     => foodify_taste_chips( $meta( 'taste' ) ),
		'groups'    => foodify_spec_model( foodify_product_spec_values( $product ) ),
		'nutrition' => foodify_nutrition_rows( foodify_product_nutrition_values( $product ) ),
		'steps'     => foodify_prep_steps( $method, $meta( 'prep_minutes' ) ),
		'faq'       => foodify_faq_pairs( $meta( 'faq' ) ),
	];
}

/**
 * The body — tabs, then the label table — under the buy box, where the
 * questions are asked. One hook, one renderer, the same renderer the preview
 * calls with fixture data.
 */
add_action( 'woocommerce_after_single_product_summary', static function (): void {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}
	echo foodify_render_pdp_body( foodify_pdp_data( $product ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the renderer; `about` is the_content output.
}, 6 );

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
