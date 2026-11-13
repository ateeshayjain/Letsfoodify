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
	// The method arrives as the attribute's SLUG from a real product —
	// "hot_water", "drinking-water" (foodify_prep_method) — and as its label
	// from the prototype ("Just add hot water"). Matching on the label alone,
	// no real product page had a How to make tab (first WooCommerce render,
	// 13 Nov 2026).
	$m    = strtolower( trim( str_replace( [ '_', '-' ], ' ', $method ) ) );
	$time = '' !== trim( $minutes ) ? trim( $minutes ) : '6 minutes';

	if ( false !== strpos( $m, 'hot water' ) ) {
		return [
			[ 'n' => 1, 'title' => 'Tip it into a bowl',   'detail' => 'The whole pack. No pan, no measuring.' ],
			[ 'n' => 2, 'title' => 'Add boiling water',    'detail' => 'To the line on the pack, and stir once.' ],
			[ 'n' => 3, 'title' => "Wait {$time}",         'detail' => 'Cover it. Stir again and eat.' ],
		];
	}
	// The cold-water range is Flavors — chutneys (SCOPE.md §2). These steps said
	// "Tip it into a glass … Stir and drink", which reads as a drink and is wrong
	// on every product the method applies to.
	if ( false !== strpos( $m, 'drinking water' ) || false !== strpos( $m, 'cold water' ) ) {
		return [
			[ 'n' => 1, 'title' => 'Tip it into a bowl',   'detail' => 'The whole sachet.' ],
			[ 'n' => 2, 'title' => 'Add drinking water',   'detail' => 'Room temperature is fine, a little at a time.' ],
			[ 'n' => 3, 'title' => 'Stir and serve',       'detail' => 'No grinding, no heating.' ],
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
 * The Jain badge is a claim about what is IN the food, so it is checked against
 * the ingredient list rather than trusted from the tag: a product tagged Jain
 * whose ingredients name onion, garlic or a root vegetable shows no Jain badge (see
 * foodify_jain_conflicts). No ingredient list, nothing to contradict the tag.
 *
 * @param array{in_stock?:bool,bestseller?:bool,new?:bool,dietary?:array<int,string>,ingredients?:string} $s
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
	$jain    = in_array( 'jain', array_map( 'strval', (array) ( $s['dietary'] ?? [] ) ), true )
		&& [] === foodify_jain_conflicts( (string) ( $s['ingredients'] ?? '' ) );
	$dietary = $jain ? 'Jain' : '';
	return [ 'commercial' => $commercial, 'dietary' => $dietary ];
}

/**
 * What in an ingredient list rules out "Jain". Returns the offending words as
 * written, so an editor can be told exactly what to fix; [] means no conflict.
 *
 * The client's rule (7 Nov 2026): anything that contains onion, garlic or a
 * root vegetable cannot be Jain. That includes the onion family by other names
 * and the Hindi names, because Indian labels use both. Matched as whole words,
 * so "Onion-free" and "no garlic" — which say the opposite — do not count.
 *
 * Two judgement calls, both on the strict side because a wrong "Jain" is a
 * broken promise while a missing one is only a missing badge:
 *  - GINGER counts in any form, dried (sonth) included. Many Jain kitchens
 *    accept dry ginger; an ingredient list rarely says which form it is.
 *  - Tapioca / sabudana counts: it is made from cassava root.
 * Turmeric is a rhizome but is NOT listed — dried haldi is a staple of Jain
 * cooking. Both calls confirmed by the client, 7 Nov 2026. The tests name
 * each word, so changing either is deliberate.
 *
 * @return array<int,string>
 */
function foodify_jain_conflicts( string $ingredients ): array {
	$words = implode( '|', [
		// onion and garlic
		'onions?', 'garlic', 'shallots?', 'leeks?', 'chives?', 'scallions?',
		'pyaa?z', 'piyaz', 'kanda', 'lehsun', 'lahsun', 'lasun',
		// root vegetables
		'potato(?:es)?', 'aloo', 'ginger', 'adrak', 'sonth', 'saunth',
		'carrots?', 'gajar', 'beetroots?', 'beets?', 'chukandar',
		'radish(?:es)?', 'horseradish', 'mooli', 'turnips?', 'shalgam',
		'shakarkandi', 'yams?', 'suran', 'jimikand', 'arbi', 'colocasia', 'taro',
		'cassava', 'tapioca', 'sabudana',
	] );
	if ( ! preg_match_all( '/\b(' . $words . ')\b/iu', $ingredients, $m, PREG_OFFSET_CAPTURE ) ) {
		return [];
	}
	$found = [];
	foreach ( $m[1] as [ $word, $at ] ) {
		$before = strtolower( substr( $ingredients, max( 0, $at - 8 ), min( 8, $at ) ) );
		$after  = strtolower( substr( $ingredients, $at + strlen( $word ), 6 ) );
		if ( preg_match( '/\b(no|without)\s+$/', $before ) || preg_match( '/^[\s-]*free\b/', $after ) ) {
			continue;   // "no onion", "garlic-free": a statement of absence
		}
		$found[] = $word;
	}
	return array_values( array_unique( $found ) );
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
 * The tabbed body: About · Ingredients & nutrition · How to make · FAQ.
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
		$sections[] = [ 'id' => 'cook', 'title' => 'How to make', 'html' => $html . '</ol>' ];
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
		// The allergens row is the one a buyer scans for (PRODUCT-20): it carries
		// its own class so it can be set in bold.
		$cls = array_filter( [
			'allergens' === ( $row['key'] ?? '' ) ? 'fd-spec__row--allergens' : '',
			$row['provided'] ? '' : 'is-missing',
		] );
		$html .= sprintf(
			'<div%1$s><dt>%2$s</dt><dd>%3$s</dd></div>',
			$cls ? ' class="' . implode( ' ', $cls ) . '"' : '',
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
		$out .= '<p class="fd-spec__note">These are the declarations printed on the pack.</p></section>';
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
    function update(){var q=qty?parseInt(qty.value,10)||1:1;total.textContent='₹'+fmt(unit*q);bar.querySelector('.fd-sticky-atc__qty').textContent=q+' '+(q===1?(bar.getAttribute('data-unit-one')||'pack'):(bar.getAttribute('data-unit-many')||'packs'));}
    if(qty){qty.addEventListener('input',update);qty.addEventListener('change',update);}update();
    // Shown only once the form has scrolled off the TOP. "Not intersecting" is
    // also true on first load when the button is below the fold, and the bar
    // then sat over the price it summarises (UX review, PRODUCT-2).
    new IntersectionObserver(function(en){var e=en[0];bar.hidden=e.isIntersecting||e.boundingClientRect.top>0;},{threshold:0}).observe(form);
    bar.querySelector('button').addEventListener('click',function(){var b=form.querySelector('button[type=submit],.single_add_to_cart_button');if(b){b.click();}else{form.scrollIntoView({behavior:'smooth',block:'center'});}});
  }
  // Arriving at #fd-notify (the card's "Notify me", or back from the alert
  // form) scrolls there; focus follows, so a keyboard or reader user lands on
  // the form and its message rather than at the top of the page.
  if(location.hash==='#fd-notify'){var nt=document.getElementById('fd-notify');if(nt){if(!nt.hasAttribute('tabindex'))nt.setAttribute('tabindex','-1');nt.focus();}}
})();
JS;
}

/* -------------------------------------------------------------------------
 * Lane A (Nov 2026): the veg mark, the unit price, the gallery shot list.
 * Pure; product-display.php reads the product and calls them.
 * ---------------------------------------------------------------------- */

/**
 * Does the declared diet earn FSSAI's vegetarian symbol (green square, green
 * dot)? An EXACT match on the declared value, never a substring:
 * "Non-vegetarian" contains "vegetarian", and a green dot on a non-veg pack is
 * the one labelling error an Indian buyer will not forgive. Unknown or blank is
 * NOT veg — the mark is a claim, and a missing declaration makes no claim
 * (the admin column already reports the gap). Vegan food is vegetarian food,
 * so it carries the mark too. Egg is non-vegetarian under FSSAI's rules.
 */
function foodify_diet_is_veg( string $diet ): bool {
	$d = strtolower( (string) preg_replace( '/\s+/', ' ', trim( $diet ) ) );
	return in_array( $d, [ 'vegetarian', 'veg', 'pure veg', 'pure vegetarian', 'vegan', 'jain' ], true );
}

/** The mark, or '' — drawn in CSS (.fd-veg), so no image request. */
function foodify_veg_mark_html( string $diet ): string {
	return foodify_diet_is_veg( $diet )
		? '<span class="fd-veg" role="img" aria-label="Vegetarian" title="Vegetarian"></span>'
		: '';
}

/**
 * Net quantity in base units (g or ml), or null when it cannot be read with
 * certainty. "80 g" → 80 g; "1.2 kg" → 1200 g; "3 × 80 g" / "3 packs of 80 g" /
 * "3 packs, 80 g each" → 240 g; "3 packs · 240 g" → 240 g (a stated figure
 * with no "each" is the total). Mass and volume together, or two quantities
 * that disagree, is ambiguous → null: a unit price computed from the wrong one
 * is off by the pack count, and an overstated "per 100 g" is worse than none.
 *
 * @return array{qty:float,dim:string}|null dim is 'g' or 'ml'.
 */
function foodify_net_quantity( string $raw ): ?array {
	$s = strtolower( str_replace( '×', 'x', $raw ) );
	$s = (string) preg_replace( '/(?<=\d),(?=\d{3}\b)/', '', $s );   // 1,000 g
	$units = [
		'kg' => [ 1000, 'g' ], 'kgs' => [ 1000, 'g' ], 'kilo' => [ 1000, 'g' ], 'kilos' => [ 1000, 'g' ], 'kilogram' => [ 1000, 'g' ], 'kilograms' => [ 1000, 'g' ],
		'g' => [ 1, 'g' ], 'gm' => [ 1, 'g' ], 'gms' => [ 1, 'g' ], 'gram' => [ 1, 'g' ], 'grams' => [ 1, 'g' ], 'gramme' => [ 1, 'g' ], 'grammes' => [ 1, 'g' ],
		'l' => [ 1000, 'ml' ], 'ltr' => [ 1000, 'ml' ], 'ltrs' => [ 1000, 'ml' ], 'litre' => [ 1000, 'ml' ], 'litres' => [ 1000, 'ml' ], 'liter' => [ 1000, 'ml' ], 'liters' => [ 1000, 'ml' ],
		'ml' => [ 1, 'ml' ], 'millilitre' => [ 1, 'ml' ], 'millilitres' => [ 1, 'ml' ], 'milliliter' => [ 1, 'ml' ], 'milliliters' => [ 1, 'ml' ],
	];
	$unit_re = implode( '|', array_map( static fn( $u ): string => preg_quote( (string) $u, '/' ), array_keys( $units ) ) );
	$qty_re  = '(\d+(?:\.\d+)?)\s*(' . $unit_re . ')\b';
	$count   = '\b(\d{1,3})\s*';
	$packs   = '(?:packs?|pouches?|sachets?|pieces?|pcs)';

	$found = [];
	$take  = static function ( float $n, string $u, int $mult ) use ( $units, &$found ): void {
		[ $f, $dim ] = $units[ $u ];
		$found[]     = [ $n * $f * $mult, $dim ];
	};
	// "3 x 80 g", "3 packs of 80 g", "3 sachets x 80 g"
	if ( preg_match_all( '/' . $count . '(?:x|' . $packs . '\s*(?:of|x))\s*' . $qty_re . '/', $s, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $hit ) {
			$take( (float) $hit[2], $hit[3], (int) $hit[1] );
			$s = str_replace( $hit[0], ' ', $s );
		}
	}
	// "3 packs, 80 g each"
	if ( preg_match( '/' . $count . $packs . '\b.*?' . $qty_re . '\s*each\b/', $s, $hit ) ) {
		$take( (float) $hit[2], $hit[3], (int) $hit[1] );
		$s = str_replace( $hit[0], ' ', $s );
	}
	if ( preg_match_all( '/' . $qty_re . '/', $s, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $hit ) {
			$take( (float) $hit[1], $hit[2], 1 );
		}
	}
	if ( ! $found ) {
		return null;
	}
	[ $qty, $dim ] = $found[0];
	foreach ( $found as [ $q, $d ] ) {
		if ( $d !== $dim || abs( $q - $qty ) > 0.001 ) {
			return null;
		}
	}
	return $qty > 0 ? [ 'qty' => (float) $qty, 'dim' => $dim ] : null;
}

/** ₹1,23,456.50 — Indian grouping; ".00" dropped, any other paise kept to two places. */
function foodify_inr( float $amount ): string {
	$cents  = (int) round( $amount * 100 );
	$whole  = intdiv( $cents, 100 );
	$paise  = $cents % 100;
	$digits = (string) $whole;
	if ( strlen( $digits ) > 3 ) {
		$head   = (string) preg_replace( '/\B(?=(\d{2})+$)/', ',', substr( $digits, 0, -3 ) );
		$digits = $head . ',' . substr( $digits, -3 );
	}
	return '₹' . $digits . ( $paise > 0 ? '.' . str_pad( (string) $paise, 2, '0', STR_PAD_LEFT ) : '' );
}

/**
 * The unit price under the price: "₹231.25/100 g" below 1 kg (or 1 l), "₹450/kg"
 * at or above it. Null — nothing printed — when the price or the quantity
 * cannot be read with certainty.
 *
 * The Legal Metrology (Packaged Commodities) Amendment Rules 2022 made "unit
 * sale price" a mandatory pack declaration. As we read them — NOT verified
 * against the gazette text, see docs/FEATURES-A-NOTES.md — the rule's basis
 * below 1 kg is PER GRAM, not per 100 g; per 100 g is the comparison
 * convention Amazon and Blinkit use and what the brief asked for. This line
 * is a shopping aid on the selling price; the MRP row in Pack & label is the
 * pack declaration.
 */
function foodify_unit_price( float $price, string $net_quantity ): ?string {
	$q = foodify_net_quantity( $net_quantity );
	if ( null === $q || $price <= 0 ) {
		return null;
	}
	$whole = $q['qty'] >= 1000;
	$unit  = $whole ? ( 'g' === $q['dim'] ? 'kg' : 'l' ) : '100 ' . $q['dim'];
	return foodify_inr( $price / $q['qty'] * ( $whole ? 1000 : 100 ) ) . '/' . $unit;
}

/**
 * The gallery's shot list, in order. The preview's photo brief and any
 * completeness check read THIS list, so the brief the photographer gets and
 * the gap the editor is told about cannot disagree.
 *
 * The back of pack is required: it is the printed label — ingredients,
 * allergens, nutrition, FSSAI number, best-before — as the pack itself states
 * it. The structured table says the same things, but a buyer checking an
 * allergen trusts the pack, and Amazon and Blinkit both show it.
 *
 * @return array<int,array{n:int,key:string,title:string,detail:string,required:bool}>
 */
function foodify_gallery_shots(): array {
	return [
		[ 'n' => 1, 'key' => 'front',       'title' => 'Pack, front',               'detail' => 'Straight on, label legible. This is the Merchant Center image.', 'required' => true ],
		[ 'n' => 2, 'key' => 'prepared',    'title' => 'Prepared, in a bowl',       'detail' => 'The actual portion, natural light, no styling props.',           'required' => true ],
		[ 'n' => 3, 'key' => 'ingredients', 'title' => 'Ingredients, laid out',     'detail' => 'Proves it is food, not powder. Answers the real objection.',     'required' => true ],
		[ 'n' => 4, 'key' => 'scale',       'title' => 'In a hand or beside a mug', 'detail' => 'Scale. 80 g means nothing without something next to it.',        'required' => true ],
		[ 'n' => 5, 'key' => 'back',        'title' => 'Back of pack — the printed label, legible',
			'detail' => 'Flat and sharp, every word readable at full size: ingredients, allergens, nutrition, FSSAI number, best-before.', 'required' => true ],
	];
}

/**
 * Required shots a product does not have — the same "gap" the spec table
 * reports for a missing declaration, for photographs.
 *
 * @param array<int,string> $present Shot keys present (foodify_shot_keys_from_tags()).
 * @return array<int,string> Missing keys, in shot order.
 */
function foodify_gallery_gaps( array $present ): array {
	$missing = [];
	foreach ( foodify_gallery_shots() as $shot ) {
		if ( $shot['required'] && ! in_array( $shot['key'], $present, true ) ) {
			$missing[] = $shot['key'];
		}
	}
	return $missing;
}

/**
 * Which shot each image is. An image tagged with its shot key (attachment meta
 * `_foodify_shot`) is that shot; an untagged one takes the next untaken shot
 * in list order — featured image first, then the gallery — which is the order
 * the brief asks the photographer for. So five untagged images cover the list,
 * and four leave the back of pack as the gap.
 *
 * @param array<int,string> $tags One entry per image, in gallery order: its tag, or ''.
 * @return array<int,string>
 */
function foodify_shot_keys_from_tags( array $tags ): array {
	$keys = array_column( foodify_gallery_shots(), 'key' );
	$free = array_values( array_diff( $keys, $tags ) );
	$out  = [];
	foreach ( $tags as $tag ) {
		if ( in_array( $tag, $keys, true ) ) {
			$out[] = $tag;
		} elseif ( $free ) {
			$out[] = array_shift( $free );
		}
	}
	return array_values( array_unique( $out ) );
}

/* -------------------------------------------------------------------------
 * UX review, 8 Nov 2026 — product page and card fixes. Pure; the hooks in
 * product-display.php, offers.php and reviews.php call them, and so can the
 * prototype.
 * ---------------------------------------------------------------------- */

/**
 * The dietary tags a product page prints beside the veg mark, in a fixed order.
 * Input: the product's pa_dietary slugs (or their labels — the prototype passes
 * labels). "Jain" is a claim about what is IN the food, so it obeys the same
 * ingredient check as the card badge (foodify_jain_conflicts). Unknown values
 * are dropped rather than printed raw.
 *
 * @param array<int,string> $values
 * @return array<int,string> Labels.
 */
function foodify_diet_tags( array $values, string $ingredients = '' ): array {
	$known = [
		'jain'         => 'Jain',
		'vegan'        => 'Vegan',
		'gluten-free'  => 'Gluten free',
		'millet'       => 'Millet based',
		'high-protein' => 'High protein',
	];
	$alias = [ 'millet-based' => 'millet' ];
	$have  = [];
	foreach ( $values as $v ) {
		$k = trim( (string) preg_replace( '/[\s_]+/', '-', strtolower( trim( (string) $v ) ) ), '-' );
		$have[ $alias[ $k ] ?? $k ] = true;
	}
	$out = [];
	foreach ( $known as $slug => $label ) {
		if ( empty( $have[ $slug ] ) ) {
			continue;
		}
		if ( 'jain' === $slug && [] !== foodify_jain_conflicts( $ingredients ) ) {
			continue;   // the tag says Jain, the ingredients say otherwise
		}
		$out[] = $label;
	}
	return $out;
}

/**
 * The line under the product page's H1: the commercial badge the card shows
 * (Bestseller / New / Sold out — foodify_card_badges) and every dietary tag.
 * Before: the card said Bestseller · Jain, the page it opened said neither.
 *
 * @param array<int,string> $diet Labels from foodify_diet_tags().
 */
function foodify_pdp_tags_html( string $commercial, array $diet ): string {
	$e   = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES );
	$out = '';
	if ( '' !== $commercial ) {
		$out .= '<span class="fd-badge ' . ( 'Sold out' === $commercial ? 'fd-badge--out' : 'fd-badge--flame' ) . '">' . $e( $commercial ) . '</span>';
	}
	foreach ( $diet as $label ) {
		$out .= '<span class="fd-badge fd-badge--leaf">' . $e( (string) $label ) . '</span>';
	}
	return '' === $out ? '' : '<p class="fd-pdp-tags">' . $out . '</p>';
}

/**
 * The card's dietary line under the name (BROWSE-18): "Jain · Vegan", as text,
 * in place of a leaf badge stacked on the image — which LOOK's CSS hides
 * whenever a Sold out / Bestseller badge is there, so the tag was lost exactly
 * on the cards people look at most. At most $max tags, in foodify_diet_tags()
 * order (Jain first: the one a buyer filters on), so it stays one line.
 *
 * @param array<int,string> $tags
 */
function foodify_card_diet_html( array $tags, int $max = 2 ): string {
	$tags = array_slice( array_values( array_filter( array_map( 'strval', $tags ), static fn( $t ): bool => '' !== trim( $t ) ) ), 0, max( 1, $max ) );
	return $tags ? '<p class="fd-card-diet">' . htmlspecialchars( implode( ' · ', $tags ), ENT_QUOTES ) . '</p>' : '';
}

/**
 * The card's prep line — principle 1, "six minutes is the product": the card
 * names the method and the time FIRST, ahead of the name and the price.
 * "Hot water · 6 min · Serves 2" in one line, so the card does not grow: it
 * replaces the grey "Serves 2 · 6 min" line under the name (UX review, BROWSE-1).
 *
 * @param array{label?:string,modifier?:string} $chip foodify_prep_chip_parts()
 */
function foodify_card_prep_html( array $chip, int $servings = 0, string $pack = '' ): string {
	$label = trim( (string) ( $chip['label'] ?? '' ) );
	if ( '' === $label ) {
		return '';
	}
	$e    = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES );
	$mod  = trim( (string) ( $chip['modifier'] ?? '' ) );
	// Line 2: the pack and what it feeds — "80 g · Serves 2" (client, 9 Nov 2026:
	// the size beside the price, as quick-commerce cards show "250 ml").
	$second = implode( ' · ', array_filter( [ trim( $pack ), $servings > 0 ? 'Serves ' . $servings : '' ], 'strlen' ) );
	// Method and time stay one line where they fit; where they do not, the line
	// breaks only AFTER the dot and the time moves whole ("Drinking water ·" /
	// "1 min" — never "… · 1" / "min"). Client, 9 Nov 2026.
	$cut  = strrpos( $label, ' · ' );
	$text = false === $cut ? $e( $label )
		: $e( substr( $label, 0, $cut ) ) . '&nbsp;· <span class="fd-card-prep__time">' . $e( substr( $label, $cut + strlen( ' · ' ) ) ) . '</span>';
	return '<p class="fd-card-prep"><span class="fd-chip' . ( '' !== $mod ? ' ' . $e( $mod ) : '' ) . '">' . $text . '</span>'
		. ( '' !== $second ? '<span class="fd-card-prep__serves">' . $e( $second ) . '</span>' : '' )
		. '</p>';
}

/**
 * A card's price block with the pack size put in front of its unit price —
 * "80 g · ₹231/100 g" — or, with no unit price, the pack on its own line.
 * No pack declared: the block as it was.
 *
 * On the CARD the unit price is a comparison aid and is shown to the whole
 * rupee, so the line fits a phone card ("₹231.25/100 g" wrapped mid-unit).
 * The product page keeps the exact figure (foodify_unit_price()).
 */
function foodify_card_pack_unit( string $price_html, string $pack ): string {
	$pack = trim( $pack );
	if ( '' === $pack || '' === $price_html ) {
		return $price_html;
	}
	$p    = '<span class="fd-card-pack">' . htmlspecialchars( $pack, ENT_QUOTES ) . '</span>';
	$open = '<span class="fd-unit-price">';
	if ( false !== strpos( $price_html, $open ) ) {
		return preg_replace_callback(
			'/' . preg_quote( $open, '/' ) . '(.*?)<\/span>/s',
			static function ( array $m ) use ( $open, $p ): string {
				$unit = preg_replace_callback( '/(\d[\d,]*)\.(\d{2})/', static function ( array $n ): string {
					$whole = (int) str_replace( ',', '', $n[1] ) + ( (int) $n[2] >= 50 ? 1 : 0 );
					return number_format( $whole );
				}, $m[1], 1 ) ?? $m[1];
				return $open . $p . ' · <span class="fd-card-unit">' . $unit . '</span></span>';
			},
			$price_html,
			1
		) ?? $price_html;
	}
	$end = strrpos( $price_html, '</div>' );
	return false === $end ? $price_html . $open . $p . '</span>'
		: substr( $price_html, 0, $end ) . $open . $p . '</span>' . substr( $price_html, $end );
}

/**
 * Short allergen names from the declared allergens text, for a one-line
 * "Contains:" under the yield strip (PRODUCT-20). "Milk (ghee), Cereals
 * containing gluten (wheat, in the asafoetida)" → [milk, gluten]. A
 * declaration of none, a missing declaration and "may contain" statements
 * give nothing here: the table row says those in full, and a one-line summary
 * must never shorten "may contain" into "contains" or "not provided" into
 * silence that reads as safe.
 *
 * @return array<int,string>
 */
function foodify_allergen_names( string $raw ): array {
	$raw = trim( $raw );
	if ( '' === $raw || preg_match( '/^(none|nil|no\b|not provided)|\bnone of\b/i', $raw ) ) {
		return [];
	}
	$flat  = (string) preg_replace( '/\([^)]*\)/', '', $raw );
	$names = [];
	foreach ( preg_split( '/[,;]|\band\b/i', $flat ) ?: [] as $part ) {
		$p = strtolower( trim( (string) preg_replace( '/\s+/', ' ', $part ), " .\t" ) );
		if ( '' === $p || str_starts_with( $p, 'may contain' ) || str_contains( $p, 'traces' ) ) {
			continue;
		}
		if ( str_contains( $p, 'gluten' ) ) {
			$p = 'gluten';
		} elseif ( str_contains( $p, 'sulphite' ) || str_contains( $p, 'sulfite' ) ) {
			$p = 'sulphites';
		} elseif ( str_contains( $p, 'soy' ) ) {
			$p = 'soy';
		} elseif ( str_contains( $p, 'crustacea' ) ) {
			$p = 'crustaceans';
		}
		$names[] = $p;
	}
	return array_values( array_unique( $names ) );
}

/** '<p class="fd-allergens"><strong>Contains:</strong> milk, gluten</p>' or ''. */
function foodify_allergen_line_html( string $raw ): string {
	$names = foodify_allergen_names( $raw );
	return $names
		? '<p class="fd-allergens"><strong>Contains:</strong> ' . htmlspecialchars( implode( ', ', $names ), ENT_QUOTES ) . '</p>'
		: '';
}

/**
 * The assurances under Add to cart, from the product's own data (PRODUCT-4).
 * They used to be typed into the template — "Ready in 6 minutes" and "9–12
 * month shelf life" on every product, a 1-minute chutney and a 12-month label
 * included. The prep time is gone from here entirely: the yield strip states it,
 * from the product, one place per fact. Shelf life comes from the product's
 * declared shelf life and is left out when there is none. Cash on delivery is a
 * way to pay, so it lives here, not under Offers (foodify_cod_line()). Sold out,
 * the lines about buying (COD) go.
 *
 * @param array{shelf_life?:string,cod?:string,in_stock?:bool} $d
 * @return array<int,string>
 */
function foodify_assurance_lines( array $d ): array {
	$in    = ! isset( $d['in_stock'] ) || (bool) $d['in_stock'];
	$lines = [ 'No preservatives' ];
	$shelf = trim( (string) ( $d['shelf_life'] ?? '' ) );
	if ( '' !== $shelf ) {
		$lines[] = 'Shelf life: ' . $shelf;
	}
	$cod = trim( (string) ( $d['cod'] ?? '' ) );
	if ( $in && '' !== $cod ) {
		$lines[] = $cod;
	}
	return $lines;
}

/** The small print under the assurances (PRODUCT-14 wording). */
function foodify_assurance_note( bool $in_stock ): string {
	return $in_stock
		? 'Delivery charge, if any, shows in your cart. MRP includes all taxes.'
		: 'MRP includes all taxes.';
}

function foodify_assurance_html( array $lines, string $note = '' ): string {
	if ( ! $lines && '' === $note ) {
		return '';
	}
	$e   = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES );
	$out = '<div class="fd-assure">';
	foreach ( $lines as $l ) {
		$out .= '<p class="fd-assure__item">' . $e( (string) $l ) . '</p>';
	}
	return $out . ( '' !== $note ? '<p class="fd-assure__note">' . $e( $note ) . '</p>' : '' ) . '</div>';
}

/**
 * The sale price as a screen reader hears it (PRODUCT-16). WooCommerce marks
 * the struck-through MRP aria-hidden, so a reader heard only "₹185" — never
 * that it was ₹225. Visually unchanged; the reader hears "MRP ₹225, now ₹185".
 * Prices arrive as WooCommerce's own (already escaped) wc_price() markup; the
 * plain texts are escaped here.
 */
function foodify_sale_price_html( string $regular_html, string $sale_html, string $regular_text ): string {
	return '<span class="fd-sr">MRP ' . htmlspecialchars( trim( $regular_text ), ENT_QUOTES ) . ', now </span>'
		. '<del aria-hidden="true">' . $regular_html . '</del> <ins>' . $sale_html . '</ins>';
}

/**
 * A card button's text once the product is in the cart (BROWSE-7). WooCommerce
 * says "1 in cart" and the button still adds one more on every press — a label
 * that reads as a state, on a control that performs an action. WooCommerce 11
 * builds that text from __( '%s in cart', 'woocommerce' ) in PHP and swaps '###'
 * for the count in the browser (ProductButton.php, frontend.ts), so one
 * translation filter changes both. The count stays visible, after the action.
 */
function foodify_in_cart_text(): string {
	// A no-break space before the dot: on a two-up phone card the button
	// wraps to "Add another ·" / "1 in cart", never a line starting "·".
	return "Add another\u{00A0}· %s in cart";
}

/** A sold-out card button's accessible name (BROWSE-21). */
function foodify_sold_out_button_label( string $name, bool $accepting_alerts ): string {
	$name = trim( $name );
	return $accepting_alerts ? "Notify me when {$name} is back" : "{$name} is sold out";
}

/**
 * Sold-out products last in every listing sort (BROWSE-8) — the same rule the
 * search ranking applies (foodify_search_rank: in stock before sold out, then
 * the order asked for). Adds a LEFT JOIN on WooCommerce's product lookup table
 * and prefixes ORDER BY, so "Price: low to high" stays price order within each
 * half. A product with no lookup row sorts as in stock (NULL first). Idempotent.
 *
 * @param array{join?:string,orderby?:string} $clauses WP_Query posts_clauses
 */
function foodify_sold_out_last_clauses( array $clauses, string $posts_table, string $lookup_table ): array {
	$alias = 'fd_stock';
	$join  = (string) ( $clauses['join'] ?? '' );
	if ( str_contains( $join, " {$alias} ON " ) ) {
		return $clauses;
	}
	$clauses['join']    = $join . " LEFT JOIN {$lookup_table} {$alias} ON {$alias}.product_id = {$posts_table}.ID";
	$order              = trim( (string) ( $clauses['orderby'] ?? '' ) );
	$clauses['orderby'] = "( {$alias}.stock_status = 'outofstock' ) ASC" . ( '' !== $order ? ', ' . $order : '' );
	return $clauses;
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
		'mrp'          => $product->get_regular_price() ? html_entity_decode( wp_strip_all_tags( wc_price( (float) $product->get_regular_price() ) ), ENT_QUOTES, 'UTF-8' ) . ' (incl. all taxes)' : '',
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
		// The meta is a bare number ("6"); a step reads "Wait 6 minutes".
		'steps'     => foodify_prep_steps( $method, ctype_digit( $meta( 'prep_minutes' ) ) ? $meta( 'prep_minutes' ) . ( '1' === $meta( 'prep_minutes' ) ? ' minute' : ' minutes' ) : $meta( 'prep_minutes' ) ),
		'faq'       => foodify_faq_pairs( $meta( 'faq' ) ),
	];
}

/**
 * The body — tabs, then the label table — under the buy box, where the
 * questions are asked. One hook, one renderer, the same renderer the preview
 * calls with fixture data.
 */
/*
 * WooCommerce's own tabs — Description, Additional information, Reviews — are
 * not this page's: the description is the About tab below, the attributes are
 * the badges under the name, and reviews have their own section further down
 * (the product-reviews block). Left on, they printed ABOVE this body, unstyled,
 * with a second review form (first real WooCommerce render, 13 Nov 2026). A
 * plugin that adds a tab of its own still gets it.
 */
add_filter( 'woocommerce_product_tabs', static function ( $tabs ) {
	return array_diff_key( (array) $tabs, [ 'description' => 1, 'additional_information' => 1, 'reviews' => 1 ] );
}, 98 );

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
