<?php
/**
 * 2: product search + suggestions.
 *
 * WHAT A CUSTOMER GETS
 * --------------------
 * A search control in the site header. On a phone it is a 44px icon that opens
 * a full-width field under the header row; from 1200px up it is a field beside
 * the account and cart. It is a plain GET form to `/?s=…&post_type=product`, so
 * it works with JavaScript off and on a cached page. With JavaScript, the
 * suggestions appear as you type — computed IN THE BROWSER from a small index
 * of the catalogue, so typing costs no request per keystroke.
 *
 * WHY THE INDEX IS A REST ENDPOINT, NOT JSON PRINTED INTO THE PAGE
 * ----------------------------------------------------------------
 * The storefront HTML is page-cached (WP-04 depends on it). 44 rows with
 * keywords are ~8 KB; printed into every cached page that is 8 KB on every
 * page view for every visitor, most of whom never search — and it goes stale
 * for as long as the cached page lives, so a sold-out meal keeps suggesting
 * itself. The endpoint is fetched ONCE, on the first focus of the field, sent
 * with `Cache-Control: public, max-age=600` and an ETag, and kept in
 * sessionStorage for ten minutes. Visitors who never search never pay for it;
 * a stock change reaches suggestions within ten minutes, not within the page
 * cache's lifetime. It is not on the page-load critical path, so it does not
 * count against the WP-04 request budget.
 *
 * THE RANKING IS WRITTEN TWICE, AND TESTED AS ONE
 * -----------------------------------------------
 * The browser ranks suggestions; PHP ranks the results page (pre_get_posts),
 * so "rajma" suggests the same products the results page lists. Two
 * implementations of one rule drift, so tests/search-test.php runs the SAME
 * vectors through foodify_search_rank() and through the JavaScript engine in
 * node, and fails if they disagree on a single id or its order.
 *
 * Tiers, best first:
 *   0  the name starts with the query              "dal f"  → Dal Fry
 *   1  every query word starts a word of the name   "fry"    → Dal Fry
 *   2  …or of the keywords (tags, Hindi names, ingredients)  "rajma", "moong"
 *   3  …allowing ONE typo on query words of 4+ letters       "biryni", "idly"
 *   Sold-out products come after every in-stock one, whatever their tier.
 *   Within a tier, catalogue order — the index is built by popularity.
 *
 * @package Foodify
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Pure — tested in tests/search-test.php without WordPress.
 * ---------------------------------------------------------------------- */

/**
 * Below this length a query word must match exactly (as a prefix); at or above
 * it, one typo is forgiven. 4 since the UX fix wave (8 Nov 2026, BROWSE-9):
 * the menu's commonest names are four letters — idli, poha, upma, chai — and
 * "idly" found nothing. Three letters stays exact ("dal" must not find "dol").
 */
const FOODIFY_SEARCH_TYPO_MIN = 4;

/**
 * The cached index's key, VERSIONED by the row's shape. Rows gained cats, tags
 * and ing (smart search, 9 Nov 2026); a 12-hour transient of the old shape would
 * have left every group empty after deploy until some product happened to be
 * saved. A new shape is a new key — no flush to remember at cutover.
 */
const FOODIFY_SEARCH_INDEX_KEY = 'foodify_search_index_v2';

/**
 * Lower-case, punctuation to spaces, single spaces. Letters, combining marks and
 * digits survive in every script — \p{M} is what keeps a Devanagari vowel sign
 * attached to its consonant, so "राजमा" is still one word.
 */
function foodify_search_norm( string $s ): string {
	$s = mb_strtolower( $s, 'UTF-8' );
	$s = preg_replace( '/[^\p{L}\p{M}\p{N}]+/u', ' ', $s ) ?? '';
	return trim( preg_replace( '/ +/', ' ', $s ) ?? '' );
}

/** @return array<int,string> */
function foodify_search_words( string $s ): array {
	$n = foodify_search_norm( $s );
	return '' === $n ? [] : explode( ' ', $n );
}

/**
 * At most one edit apart (insert, delete, substitute, or swap two neighbours)?
 * On code points, not bytes — PHP's levenshtein() counts one Devanagari letter
 * as three edits.
 *
 * @param array<int,string> $a
 * @param array<int,string> $b
 */
function foodify_search_within_one( array $a, array $b ): bool {
	$la = count( $a );
	$lb = count( $b );
	if ( abs( $la - $lb ) > 1 ) {
		return false;
	}
	if ( $la === $lb ) {
		$diff = [];
		for ( $i = 0; $i < $la; $i++ ) {
			if ( $a[ $i ] !== $b[ $i ] ) {
				$diff[] = $i;
				if ( count( $diff ) > 2 ) {
					return false;
				}
			}
		}
		if ( count( $diff ) <= 1 ) {
			return true;
		}
		[ $i, $j ] = $diff;
		return $j === $i + 1 && $a[ $i ] === $b[ $j ] && $a[ $j ] === $b[ $i ];
	}
	// One is longer by exactly one: skipping a single character must align them.
	[ $s, $l ] = $la < $lb ? [ $a, $b ] : [ $b, $a ];
	$i       = 0;
	$j       = 0;
	$skipped = false;
	while ( $i < count( $s ) && $j < count( $l ) ) {
		if ( $s[ $i ] === $l[ $j ] ) {
			$i++;
			$j++;
			continue;
		}
		if ( $skipped ) {
			return false;
		}
		$skipped = true;
		$j++;
	}
	return true;
}

/** Is query word $t a prefix of word $w? */
function foodify_search_prefix( string $t, string $w ): bool {
	return '' !== $t && 0 === strncmp( $w, $t, strlen( $t ) );
}

/**
 * One typo, against the whole word or against the start of it — someone typing
 * "biryn" is part-way through "biryani" with one slip in.
 */
function foodify_search_typo( string $t, string $w ): bool {
	$tc = mb_str_split( $t );
	if ( count( $tc ) < FOODIFY_SEARCH_TYPO_MIN ) {
		return false;
	}
	$wc = mb_str_split( $w );
	if ( foodify_search_within_one( $tc, $wc ) ) {
		return true;
	}
	foreach ( [ count( $tc ), count( $tc ) + 1 ] as $n ) {
		if ( count( $wc ) > $n && foodify_search_within_one( $tc, array_slice( $wc, 0, $n ) ) ) {
			return true;
		}
	}
	return false;
}

/**
 * The tier a row earns for a query, or -1 for no match.
 *
 * @param array<int,string> $tokens Normalised query words.
 */
function foodify_search_tier( array $row, string $qn, array $tokens ): int {
	$name_n = foodify_search_norm( (string) ( $row['name'] ?? '' ) );
	if ( '' === $name_n ) {
		return -1;
	}
	if ( str_starts_with( $name_n, $qn ) ) {
		return 0;
	}
	$name_w = explode( ' ', $name_n );
	$all_w  = array_merge( $name_w, foodify_search_words( (string) ( $row['kw'] ?? '' ) ) );

	$every = static function ( array $words, bool $typo ) use ( $tokens ): bool {
		foreach ( $tokens as $t ) {
			$hit = false;
			foreach ( $words as $w ) {
				if ( foodify_search_prefix( $t, $w ) || ( $typo && foodify_search_typo( $t, $w ) ) ) {
					$hit = true;
					break;
				}
			}
			if ( ! $hit ) {
				return false;
			}
		}
		return true;
	};

	if ( $every( $name_w, false ) ) {
		return 1;
	}
	if ( $every( $all_w, false ) ) {
		return 2;
	}
	if ( $every( $all_w, true ) ) {
		return 3;
	}
	return -1;
}

/**
 * Rank catalogue rows for a query.
 *
 * @param array<int,array{id:int,name:string,kw?:string,stock?:bool}> $rows In catalogue (popularity) order.
 * @return array<int,array> The matching rows, unchanged, best first, at most $limit.
 */
function foodify_search_rank( array $rows, string $q, int $limit = 6 ): array {
	$qn = foodify_search_norm( $q );
	if ( '' === $qn || $limit < 1 ) {
		return [];
	}
	$tokens = explode( ' ', $qn );
	$hits   = [];
	foreach ( array_values( $rows ) as $i => $row ) {
		$tier = foodify_search_tier( (array) $row, $qn, $tokens );
		if ( $tier < 0 ) {
			continue;
		}
		$hits[] = [ 'out' => empty( $row['stock'] ) ? 1 : 0, 'tier' => $tier, 'i' => $i, 'row' => $row ];
	}
	usort( $hits, static fn( array $x, array $y ): int => [ $x['out'], $x['tier'], $x['i'] ] <=> [ $y['out'], $y['tier'], $y['i'] ] );
	return array_map( static fn( array $h ) => $h['row'], array_slice( $hits, 0, $limit ) );
}

/** The first $n IN-STOCK rows — the index is popularity-ordered. Shown when nothing matches. */
function foodify_search_popular( array $rows, int $n = 3 ): array {
	$out = [];
	foreach ( $rows as $row ) {
		if ( count( $out ) >= $n ) {
			break;
		}
		if ( ! empty( $row['stock'] ) ) {
			$out[] = $row;
		}
	}
	return $out;
}

/**
 * The last option in the suggestions (BROWSE-9): "See all 7 results for ‘dal’".
 * The browser prints the same words (FDSearch.seeAll); the vectors compare them.
 */
function foodify_search_see_all_text( string $q, int $n ): string {
	$q = trim( preg_replace( '/\s+/u', ' ', $q ) ?? '' );
	return 1 === $n ? 'See 1 result for ‘' . $q . '’' : 'See all ' . $n . ' results for ‘' . $q . '’';
}

/**
 * The results page's own search field (BROWSE-10): the query, in an open field
 * at the top, so a customer can correct "biryni" without hunting for the
 * header icon. A plain GET form like the header's — works with no script.
 */
function foodify_search_results_form_html( string $q ): string {
	$e = static fn( string $s ): string => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
	return '<form class="fd-search-again" role="search" method="get" action="/">'
		. '<label class="fd-search-again__label" for="fd-search-again-q">Search the menu</label>'
		. '<span class="fd-search-again__row">'
		. '<input class="fd-search-again__input" id="fd-search-again-q" type="search" name="s" value="' . $e( trim( $q ) ) . '" autocomplete="off" enterkeyhint="search">'
		. '<input type="hidden" name="post_type" value="product">'
		. '<button class="wp-element-button fd-search-again__go" type="submit">Search</button>'
		. '</span></form>';
}

/**
 * Should the results count ("No products found") be dropped? Only on a search
 * with nothing found: the page then says "No match for ‘xyz’. Popular right
 * now:" and shows three products, and WooCommerce's line under the heading
 * contradicts the products right below it (BROWSE-10).
 */
function foodify_search_hide_results_count( bool $is_search, int $found ): bool {
	return $is_search && $found < 1;
}

/** "No match for ‘xyz’" — the same words the browser prints. */
function foodify_search_none_text( string $q ): string {
	return 'No match for ‘' . trim( preg_replace( '/\s+/u', ' ', $q ) ?? '' ) . '’';
}

/**
 * Keywords for one product: unique normalised words from a free keyword field
 * (Hindi and regional names), tags, categories and the ingredients, minus the
 * words already in the name. Digits-only words ("45" from "Rice (45%)") and
 * single letters are dropped — they match everything and mean nothing.
 *
 * @param array<int,string> $parts In priority order: the cap keeps the first words.
 */
function foodify_search_keywords( string $name, array $parts, int $max = 60 ): string {
	$skip = array_flip( foodify_search_words( $name ) );
	foreach ( [ 'and', 'with', 'the', 'of', 'in', 'for', 'or' ] as $stop ) {
		$skip[ $stop ] = true;
	}
	$out = [];
	foreach ( $parts as $part ) {
		foreach ( foodify_search_words( (string) $part ) as $w ) {
			if ( isset( $skip[ $w ] ) || isset( $out[ $w ] ) || mb_strlen( $w ) < 2 || preg_match( '/^\d+$/', $w ) ) {
				continue;
			}
			$out[ $w ] = true;
			if ( count( $out ) >= $max ) {
				break 2;
			}
		}
	}
	return implode( ' ', array_keys( $out ) );
}

/**
 * The ingredient list as phrases a shopper might type: "Yellow moong dal (45%),
 * toor dal, tomato, cumin" → ["yellow moong dal", "toor dal", "tomato",
 * "cumin"]. Percentages and bracketed notes go; a phrase longer than four words
 * is a sentence, not an ingredient, and is skipped. At most 15.
 *
 * @return array<int,string>
 */
function foodify_search_ingredient_phrases( string $ingredients, int $max = 15 ): array {
	$out = [];
	foreach ( preg_split( '/[,;]|\band\b/u', (string) preg_replace( '/\([^)]*\)|\d+(?:\.\d+)?\s*%/u', ' ', $ingredients ) ) ?: [] as $part ) {
		$p = trim( (string) preg_replace( '/\s+/u', ' ', mb_strtolower( (string) preg_replace( '/[^\p{L}\p{M}\s-]+/u', ' ', $part ) ) ) );
		if ( '' === $p || mb_strlen( $p ) < 3 || count( explode( ' ', $p ) ) > 4 || in_array( $p, $out, true ) ) {
			continue;
		}
		$out[] = $p;
		if ( count( $out ) >= $max ) {
			break;
		}
	}
	return $out;
}

/**
 * One index row, typed. Everything a suggestion needs to draw itself, and the
 * facets smart search groups by (client, 9 Nov 2026: "products, ingredients,
 * etc."): `cats` the categories with their links, `tags` the diet labels and
 * the prep method ("Jain", "Hot water"), `ing` the ingredient phrases.
 *
 * @return array{id:int,name:string,url:string,price:string,prep:string,img:string,kw:string,stock:bool,
 *               cats:array<int,array{0:string,1:string}>,tags:array<int,string>,ing:array<int,string>}
 */
function foodify_search_row( array $p ): array {
	$strs = static fn( $v ): array => array_values( array_filter( array_map( static fn( $s ): string => trim( (string) $s ), is_array( $v ) ? $v : [] ), 'strlen' ) );
	$cats = [];
	foreach ( is_array( $p['cats'] ?? null ) ? $p['cats'] : [] as $c ) {
		if ( is_array( $c ) && '' !== trim( (string) ( $c[0] ?? '' ) ) ) {
			$cats[] = [ trim( (string) $c[0] ), (string) ( $c[1] ?? '' ) ];
		}
	}
	return [
		'id'    => (int) ( $p['id'] ?? 0 ),
		'name'  => trim( (string) ( $p['name'] ?? '' ) ),
		'url'   => (string) ( $p['url'] ?? '' ),
		'price' => trim( (string) ( $p['price'] ?? '' ) ),
		'prep'  => trim( (string) ( $p['prep'] ?? '' ) ),
		'img'   => (string) ( $p['img'] ?? '' ),
		'kw'    => (string) ( $p['kw'] ?? '' ),
		'stock' => (bool) ( $p['stock'] ?? false ),
		'cats'  => $cats,
		'tags'  => $strs( $p['tags'] ?? [] ),
		'ing'   => $strs( $p['ing'] ?? [] ),
	];
}

/** The endpoint's body: the rows and a version that doubles as the ETag. */
function foodify_search_payload( array $rows ): array {
	$rows = array_values( array_map( 'foodify_search_row', $rows ) );
	$json = (string) json_encode( $rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	return [ 'v' => substr( md5( $json ), 0, 12 ), 'rows' => $rows ];
}

/**
 * The header control. parts/header.html carries this markup VERBATIM inside a
 * core/html block (tests/search-test.php pins the two equal), so the form works
 * with no PHP running at all — the template is the source of truth for a cached
 * page, and this function is the source of truth for the template.
 *
 * The field is always there (client, 9 Nov 2026: "the magnifying glass can
 * be within the search bar"): one field, the glass inside it at the left —
 * and the glass is the submit button, so a mouse has something to press. On
 * a phone it is a row of its own under the logo (style.css). The script adds
 * the combobox roles and the grouped suggestions.
 */
function foodify_search_form_html(): string {
	$icon = '<svg class="fd-search__icon" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="m15.5 15.5 5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
	return '<div class="fd-search" data-fd-search>'
		. '<form class="fd-search__form" role="search" method="get" action="/">'
		. '<label class="fd-sr" for="fd-search-q">Search the menu</label>'
		. '<button class="fd-search__go" type="submit">' . $icon . '<span class="fd-sr">Search</span></button>'
		. '<input class="fd-search__input" id="fd-search-q" type="search" name="s" placeholder="Search dal, moong, Jain…" autocomplete="off" enterkeyhint="search">'
		. '<input type="hidden" name="post_type" value="product">'
		. '</form>'
		. '<div class="fd-search__pop" hidden>'
		. '<p class="fd-search__none" hidden></p>'
		. '<ul class="fd-search__list" id="fd-search-list" role="listbox" aria-label="Suggestions"></ul>'
		. '</div>'
		. '<p class="fd-sr" role="status" aria-live="polite" data-fd-search-status></p>'
		. '</div>';
}

/**
 * The ranking engine in JavaScript — the mirror of the PHP above, and the part
 * the test runs in node. Defines one global, FDSearch {norm, rank, popular}.
 */
function foodify_search_engine_js(): string {
	return <<<'JS'
var FDSearch=(function(){
  var MIN=4;
  function norm(s){return String(s==null?'':s).toLowerCase().replace(/[^\p{L}\p{M}\p{N}]+/gu,' ').replace(/ +/g,' ').trim();}
  function words(s){var n=norm(s);return n===''?[]:n.split(' ');}
  function cps(s){return Array.from(s);}
  function one(a,b){
    var la=a.length,lb=b.length,i,j;
    if(Math.abs(la-lb)>1)return false;
    if(la===lb){var d=[];for(i=0;i<la;i++){if(a[i]!==b[i]){d.push(i);if(d.length>2)return false;}}
      if(d.length<=1)return true;i=d[0];j=d[1];return j===i+1&&a[i]===b[j]&&a[j]===b[i];}
    var s=la<lb?a:b,l=la<lb?b:a,sk=false;i=0;j=0;
    while(i<s.length&&j<l.length){if(s[i]===l[j]){i++;j++;continue;}if(sk)return false;sk=true;j++;}
    return true;
  }
  function prefix(t,w){return t!==''&&w.slice(0,t.length)===t;}
  function typo(t,w){
    var tc=cps(t);if(tc.length<MIN)return false;var wc=cps(w);
    if(one(tc,wc))return true;
    var ns=[tc.length,tc.length+1];
    for(var k=0;k<2;k++){if(wc.length>ns[k]&&one(tc,wc.slice(0,ns[k])))return true;}
    return false;
  }
  function tier(row,qn,tokens){
    var nn=norm(row.name);if(nn==='')return -1;
    if(nn.slice(0,qn.length)===qn)return 0;
    var nw=nn.split(' '),aw=nw.concat(words(row.kw));
    function every(ws,ty){
      for(var a=0;a<tokens.length;a++){var hit=false;
        for(var b=0;b<ws.length;b++){if(prefix(tokens[a],ws[b])||(ty&&typo(tokens[a],ws[b]))){hit=true;break;}}
        if(!hit)return false;}
      return true;
    }
    if(every(nw,false))return 1;
    if(every(aw,false))return 2;
    if(every(aw,true))return 3;
    return -1;
  }
  function rank(rows,q,limit){
    if(limit==null)limit=6;var qn=norm(q);if(qn===''||limit<1)return [];
    var tokens=qn.split(' '),hits=[];
    for(var i=0;i<rows.length;i++){var t=tier(rows[i],qn,tokens);if(t<0)continue;hits.push({o:rows[i].stock?0:1,t:t,i:i,r:rows[i]});}
    hits.sort(function(x,y){return (x.o-y.o)||(x.t-y.t)||(x.i-y.i);});
    return hits.slice(0,limit).map(function(h){return h.r;});
  }
  function popular(rows,n){if(n==null)n=3;var out=[];for(var i=0;i<rows.length&&out.length<n;i++){if(rows[i].stock)out.push(rows[i]);}return out;}
  function seeAll(q,n){q=String(q==null?'':q).replace(/\s+/g,' ').trim();return n===1?'See 1 result for ‘'+q+'’':'See all '+n+' results for ‘'+q+'’';}
  // Smart search's groups: the categories, labels (diet, prep) and ingredients
  // a query names, each with how many in-stock meals carry it. Display only —
  // the ranking above is the rule the results page mirrors in PHP. A label
  // matches when every query word starts one of its words ("moo" → "yellow
  // moong dal"); an ingredient needs 3+ letters, and one that is also a label
  // or a category is shown once, as the label.
  function facets(rows,q,max){
    if(max==null)max=4;var qn=norm(q);if(qn.length<2)return [];
    var tokens=qn.split(' '),seen={},out=[];
    function hit(label){var lw=words(label);if(!lw.length)return false;
      for(var a=0;a<tokens.length;a++){var ok=false;for(var b=0;b<lw.length;b++){if(prefix(tokens[a],lw[b])){ok=true;break;}}if(!ok)return false;}
      return true;}
    function add(kind,label,url){var k=kind+'|'+norm(label);if(!seen[k]){seen[k]={kind:kind,label:label,url:url||'',n:0};out.push(seen[k]);}seen[k].n++;}
    for(var i=0;i<rows.length;i++){var r=rows[i];if(!r.stock)continue;
      (r.cats||[]).forEach(function(c){if(hit(c[0]))add('cat',c[0],c[1]);});
      (r.tags||[]).forEach(function(t){if(hit(t))add('tag',t,'');});
      if(qn.length>=3)(r.ing||[]).forEach(function(t){if(hit(t))add('ing',t,'');});}
    var named={},rank={cat:0,tag:1,ing:2};
    out.forEach(function(f){if(f.kind!=='ing')named[norm(f.label)]=1;});
    out=out.filter(function(f){return f.kind!=='ing'||!named[norm(f.label)];});
    out.sort(function(x,y){return (rank[x.kind]-rank[y.kind])||(y.n-x.n)||(x.label<y.label?-1:x.label>y.label?1:0);});
    var lab=out.filter(function(f){return f.kind!=='ing';}).slice(0,2),ing=out.filter(function(f){return f.kind==='ing';}).slice(0,2);
    return lab.concat(ing).slice(0,max);
  }
  // An empty field: the categories and labels that hold the most meals.
  function popularFacets(rows,n){
    if(n==null)n=6;var seen={},out=[];
    function add(kind,label,url){var k=kind+'|'+norm(label);if(!seen[k]){seen[k]={kind:kind,label:label,url:url||'',n:0};out.push(seen[k]);}seen[k].n++;}
    rows.forEach(function(r){if(!r.stock)return;(r.cats||[]).forEach(function(c){add('cat',c[0],c[1]);});(r.tags||[]).forEach(function(t){add('tag',t,'');});});
    out.sort(function(x,y){return (y.n-x.n)||(x.label<y.label?-1:x.label>y.label?1:0);});
    return out.slice(0,n);
  }
  // Recent searches (client, 9 Nov 2026): newest first, one entry per search
  // however it was typed ("Dal  FRY" and "dal fry" are one), at most n, and
  // never a one-letter keystroke.
  function remember(list,q,n){
    if(n==null)n=5;q=String(q==null?'':q).trim().replace(/\s+/g,' ');
    var out=(Array.isArray(list)?list:[]).filter(function(x){return typeof x==='string'&&norm(x).length>=2;});
    if(norm(q).length<2)return out.slice(0,n);
    var k=norm(q);out=out.filter(function(x){return norm(x)!==k;});out.unshift(q);return out.slice(0,n);
  }
  return {norm:norm,rank:rank,popular:popular,seeAll:seeAll,facets:facets,popularFacets:popularFacets,remember:remember};
})();
JS;
}

/**
 * The header's one script: the engine plus the combobox. Expects the markup of
 * foodify_search_form_html(). Rows come from window.fdSearchRows when a page
 * (the prototype) provides them inline, else from window.fdSearchIndexUrl.
 *
 * Keyboard (WAI-ARIA 1.2 combobox, list autocomplete): ArrowDown / ArrowUp move
 * through the suggestions (past either end returns to the typed text), Enter
 * opens the highlighted one or submits the search when none is, Escape closes
 * the list, and a second Escape closes the field on a phone. Focus stays in the
 * input; the highlighted option is named by aria-activedescendant.
 */
function foodify_search_script(): string {
	return foodify_search_engine_js() . <<<'JS'
(function(){
  var root=document.querySelector('[data-fd-search]');if(!root)return;
  var input=root.querySelector('.fd-search__input'),pop=root.querySelector('.fd-search__pop'),list=root.querySelector('.fd-search__list'),
      none=root.querySelector('.fd-search__none'),status=root.querySelector('[data-fd-search-status]'),form=root.querySelector('form'),
      rows=null,loading=false,active=-1,KEY='fd-search-v2',RKEY='fd-recent-searches';
  // The groups' marks, as path data: a grid (category), a tag (diet, prep), a leaf (ingredient), the glass (see all).
  var ICON={cat:['M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z'],
            tag:['M3 12V4h8l9 9-8 8z','M7.5 7.2a1.3 1.3 0 1 0 0 2.6a1.3 1.3 0 1 0 0-2.6'],
            ing:['M5 19c0-8 5-13 14-14-1 9-6 14-14 14zM5 19l7-7'],
            recent:['M12 4a8 8 0 1 0 0 16a8 8 0 1 0 0-16','M12 8v4l3 2'],
            all:['M10.5 4a6.5 6.5 0 1 0 0 13a6.5 6.5 0 1 0 0-13','m15.5 15.5 5 5']},NS='http://www.w3.org/2000/svg';
  root.classList.add('is-enhanced');
  input.setAttribute('role','combobox');input.setAttribute('aria-autocomplete','list');
  input.setAttribute('aria-expanded','false');input.setAttribute('aria-controls','fd-search-list');
  try{var u=new URLSearchParams(location.search);if(u.get('post_type')==='product'&&u.get('s')){input.value=u.get('s');}}catch(e){}
  function results(q){var act=form.getAttribute('action')||'/';return act+(act.indexOf('?')<0?'?':'&')+'s='+encodeURIComponent(String(q).trim())+'&post_type=product';}
  function options(){return list.querySelectorAll('[role=option]');}
  function close(){pop.hidden=true;input.setAttribute('aria-expanded','false');input.removeAttribute('aria-activedescendant');active=-1;}
  function got(r){rows=Array.isArray(r)?r:[];loading=false;if(document.activeElement===input){show();}}
  function load(){
    if(rows||loading)return;
    if(Array.isArray(window.fdSearchRows)){got(window.fdSearchRows);return;}
    try{var c=JSON.parse(sessionStorage.getItem(KEY)||'null');if(c&&c.t>Date.now()-600000&&Array.isArray(c.rows)){got(c.rows);return;}}catch(e){}
    var url=window.fdSearchIndexUrl;if(!url||!window.fetch)return;loading=true;
    fetch(url,{credentials:'omit'}).then(function(r){return r.ok?r.json():null;}).then(function(j){
      var r=j&&Array.isArray(j.rows)?j.rows:[];try{sessionStorage.setItem(KEY,JSON.stringify({t:Date.now(),rows:r}));}catch(e){}got(r);
    }).catch(function(){loading=false;});
  }
  function head(text){var li=document.createElement('li');li.className='fd-search__head';li.setAttribute('role','presentation');li.textContent=text;list.appendChild(li);}
  // Recent searches live on THIS device only (localStorage) — never in the
  // cached page, never sent anywhere. Storage can be off (private mode): then
  // there are simply none.
  function recents(){try{var r=JSON.parse(localStorage.getItem(RKEY)||'[]');return FDSearch.remember(r,'',5);}catch(e){return [];}}
  function remember(q){try{localStorage.setItem(RKEY,JSON.stringify(FDSearch.remember(recents(),q,5)));}catch(e){}}
  // "Clear" sits outside the listbox (a listbox holds options only).
  var clear=document.createElement('button');clear.type='button';clear.className='fd-search__clear';clear.hidden=true;
  clear.textContent='Clear';clear.setAttribute('aria-label','Clear recent searches');pop.insertBefore(clear,list);
  clear.addEventListener('mousedown',function(e){e.preventDefault();});
  clear.addEventListener('click',function(){try{localStorage.removeItem(RKEY);}catch(e){}show();input.focus();status.textContent='Recent searches cleared.';});
  function item(href,cls){
    var li=document.createElement('li');li.id='fd-search-opt-'+options().length;li.className='fd-search__opt'+(cls?' '+cls:'');
    li.setAttribute('role','option');li.setAttribute('aria-selected','false');
    var a=document.createElement('a');a.href=href;a.tabIndex=-1;a.className='fd-search__link';li.appendChild(a);list.appendChild(li);return a;
  }
  function text(a,name,meta){
    var tx=document.createElement('span');tx.className='fd-search__text';
    var nm=document.createElement('span');nm.className='fd-search__name';nm.textContent=name;tx.appendChild(nm);
    if(meta){var mt=document.createElement('span');mt.className='fd-search__meta';mt.textContent=meta;tx.appendChild(mt);}
    a.appendChild(tx);
  }
  function glyph(a,kind){
    var s=document.createElement('span');s.className='fd-search__glyph';s.setAttribute('aria-hidden','true');
    var g=document.createElementNS(NS,'svg');
    [['viewBox','0 0 24 24'],['width','20'],['height','20'],['fill','none'],['stroke','currentColor'],['stroke-width','1.8'],['stroke-linejoin','round'],['stroke-linecap','round']]
      .forEach(function(p){g.setAttribute(p[0],p[1]);});
    ICON[kind].forEach(function(d){var p=document.createElementNS(NS,'path');p.setAttribute('d',d);g.appendChild(p);});
    s.appendChild(g);a.appendChild(s);
  }
  function meals(n){return n===1?'1 meal':n+' meals';}
  function facet(f){
    var a=item(f.kind==='cat'&&f.url?f.url:results(f.label),'fd-search__facet');glyph(a,f.kind);
    text(a,f.kind==='ing'?'Meals with '+f.label:f.label,(f.kind==='cat'?'Category · ':'')+meals(f.n));
  }
  function meal(row){
    var a=item(row.url,'');
    if(row.img){var im=document.createElement('img');im.src=row.img;im.alt='';im.width=48;im.height=48;im.loading='lazy';im.className='fd-search__img';a.appendChild(im);}
    else{var ph=document.createElement('span');ph.className='fd-search__img is-empty';ph.setAttribute('aria-hidden','true');a.appendChild(ph);}
    text(a,row.name,[row.prep,row.price].filter(Boolean).join(' · '));
    if(!row.stock){var so=document.createElement('span');so.className='fd-search__sold';so.textContent='Sold out';a.appendChild(so);}
  }
  function show(){
    var q=input.value;if(!rows){load();return;}
    list.textContent='';active=-1;input.removeAttribute('aria-activedescendant');none.hidden=true;none.textContent='';
    list.classList.toggle('is-chips',FDSearch.norm(q).length<2);   // popular searches are chips; results are rows
    var said='';clear.hidden=true;
    if(FDSearch.norm(q).length<2){
      // Nothing typed yet: what this shopper searched before, then where most of the menu is.
      var rc=recents(),pf=FDSearch.popularFacets(rows,6);
      if(rc.length){head('Recent searches');clear.hidden=false;
        rc.forEach(function(r){var a=item(results(r),'fd-search__facet fd-search__recent');glyph(a,'recent');text(a,r,'');a.setAttribute('data-fd-recent','');});
        said=(rc.length===1?'1 recent search, ':rc.length+' recent searches, ');}
      if(pf.length){head('Popular searches');pf.forEach(facet);}
      if(!rc.length&&!pf.length){close();return;}
      said+=pf.length+' popular searches.';
    }else{
      var fs=FDSearch.facets(rows,q,4),hit=FDSearch.rank(rows,q,5);
      if(fs.length){head('Categories and ingredients');fs.forEach(facet);}
      if(hit.length){head('Meals');hit.forEach(meal);
        var all=FDSearch.rank(rows,q,200).length,a=item(results(q),'fd-search__all');glyph(a,'all');text(a,FDSearch.seeAll(q,all),'');}
      if(!fs.length&&!hit.length){
        none.hidden=false;none.textContent='No match for ‘'+q.trim().replace(/\s+/g,' ')+'’. Popular right now:';
        FDSearch.popular(rows,3).forEach(meal);
      }
      var n=options().length;said=(none.hidden?'':none.textContent+' ')+(n===1?'1 suggestion.':n+' suggestions.');
    }
    if(!options().length){close();status.textContent=said;return;}
    pop.hidden=false;input.setAttribute('aria-expanded','true');status.textContent=said;
  }
  function move(d){
    if(pop.hidden){show();if(pop.hidden)return;}
    var items=options();if(!items.length)return;
    if(active>=0)items[active].setAttribute('aria-selected','false');
    active=(active+d+items.length+2)%(items.length+1)-1;
    if(active<0){input.removeAttribute('aria-activedescendant');return;}
    items[active].setAttribute('aria-selected','true');input.setAttribute('aria-activedescendant',items[active].id);
    if(items[active].scrollIntoView)items[active].scrollIntoView({block:'nearest'});
  }
  input.addEventListener('focus',function(){load();show();});
  input.addEventListener('input',show);
  input.addEventListener('keydown',function(e){
    if(e.key==='ArrowDown'){e.preventDefault();move(1);}
    else if(e.key==='ArrowUp'){e.preventDefault();move(-1);}
    // Opening an option clicks its own link, so whatever answers links answers this too.
    else if(e.key==='Enter'&&active>=0&&!pop.hidden){e.preventDefault();options()[active].querySelector('a').click();}
    else if(e.key==='Escape'&&!pop.hidden){e.preventDefault();close();}
  });
  list.addEventListener('mousedown',function(e){e.preventDefault();});   // a tap on a suggestion does not blur the field first
  // A search the shopper TYPED is remembered when they act on it: Enter, or any suggestion.
  form.addEventListener('submit',function(){remember(input.value);});
  list.addEventListener('click',function(e){
    var a=e.target.closest?e.target.closest('a'):null;
    if(a&&a.hasAttribute('data-fd-recent'))remember(a.textContent);else if(FDSearch.norm(input.value).length>=2)remember(input.value);
    close();
  });
  root.addEventListener('focusout',function(e){if(!root.contains(e.relatedTarget)){close();}});
})();
JS;
}

/* -------------------------------------------------------------------------
 * WordPress from here down.
 * ---------------------------------------------------------------------- */

if ( ! function_exists( 'add_action' ) ) {
	return;   // loaded by the test harness
}

/** Rows for every product a shopper can find, most popular first. Cached; rebuilt when a product changes. */
function foodify_search_index_rows(): array {
	$cached = get_transient( FOODIFY_SEARCH_INDEX_KEY );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	if ( ! function_exists( 'wc_get_products' ) ) {
		return [];
	}
	$rows     = [];
	$products = (array) wc_get_products( [
		'limit'      => 200,
		'status'     => 'publish',
		'visibility' => 'search',
		'orderby'    => 'popularity',
		'order'      => 'DESC',
		'return'     => 'objects',
	] );
	foreach ( $products as $product ) {
		if ( ! $product instanceof WC_Product ) {
			continue;
		}
		$id    = $product->get_id();
		$terms = [];
		foreach ( [ 'product_tag', 'product_cat' ] as $tax ) {
			$names = wp_get_post_terms( $id, $tax, [ 'fields' => 'names' ] );
			if ( is_array( $names ) ) {
				$terms = array_merge( $terms, $names );
			}
		}
		$chip   = function_exists( 'foodify_prep_chip_parts' ) ? foodify_prep_chip_parts( $product ) : null;
		$cats   = [];
		foreach ( (array) wp_get_post_terms( $id, 'product_cat' ) as $term ) {
			if ( $term instanceof WP_Term && 'uncategorized' !== $term->slug ) {
				$link   = get_term_link( $term );
				$cats[] = [ $term->name, is_string( $link ) ? $link : '' ];
			}
		}
		// Diet labels (pa_dietary, checked against the ingredients like the card)
		// and the prep method ("Hot water"): a shopper types "Jain", not a slug.
		$labels = [];
		if ( function_exists( 'foodify_badge_state' ) && function_exists( 'foodify_diet_tags' ) ) {
			$state  = foodify_badge_state( $product );
			$labels = foodify_diet_tags( $state['dietary'], $state['ingredients'] );
		}
		if ( $chip && '' !== (string) $chip['label'] ) {
			$labels[] = trim( explode( '·', (string) $chip['label'] )[0] );
		}
		$ingredients = (string) $product->get_meta( '_foodify_ingredients' );
		$image  = $product->get_image_id() ? wp_get_attachment_image_url( (int) $product->get_image_id(), 'woocommerce_gallery_thumbnail' ) : '';
		$rows[] = foodify_search_row( [
			'id'    => $id,
			'name'  => wp_strip_all_tags( $product->get_name() ),
			'url'   => (string) get_permalink( $id ),
			'price' => html_entity_decode( wp_strip_all_tags( wc_price( (float) wc_get_price_to_display( $product ) ) ), ENT_QUOTES, 'UTF-8' ),
			'prep'  => $chip ? (string) $chip['label'] : '',
			'img'   => is_string( $image ) ? $image : '',
			// Keyword field first: the cap keeps the first 60 words, and the
			// shop's own Hindi and regional names matter more than "salt".
			'kw'    => foodify_search_keywords( $product->get_name(), array_merge(
				[ (string) $product->get_meta( '_foodify_search_keywords' ) ],
				$labels,
				$terms,
				[ $ingredients ]
			) ),
			'stock' => $product->is_in_stock() && $product->is_purchasable(),
			'cats'  => $cats,
			'tags'  => $labels,
			'ing'   => foodify_search_ingredient_phrases( $ingredients ),
		] );
	}
	set_transient( FOODIFY_SEARCH_INDEX_KEY, $rows, 12 * HOUR_IN_SECONDS );
	return $rows;
}

/** Any product change makes the index stale. */
function foodify_search_index_flush(): void {
	delete_transient( FOODIFY_SEARCH_INDEX_KEY );
}
foreach ( [ 'woocommerce_update_product', 'woocommerce_new_product', 'woocommerce_delete_product', 'woocommerce_trash_product',
	'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'edited_product_tag', 'edited_product_cat' ] as $foodify_hook ) {
	add_action( $foodify_hook, 'foodify_search_index_flush' );
}
unset( $foodify_hook );

/**
 * GET /wp-json/foodify/v1/search-index — public catalogue data only (what the
 * shop page already shows), cacheable, revalidated by ETag. Nothing per-visitor
 * is in it, so a shared cache storing it is correct, not a leak. Built from a
 * transient, so a stranger hammering it costs a cache read, not a product query.
 */
add_action( 'rest_api_init', static function (): void {
	register_rest_route( 'foodify/v1', '/search-index', [
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => static function ( WP_REST_Request $request ) {
			$payload  = foodify_search_payload( foodify_search_index_rows() );
			$etag     = '"' . $payload['v'] . '"';
			$response = trim( (string) $request->get_header( 'if_none_match' ) ) === $etag
				? new WP_REST_Response( null, 304 )
				: new WP_REST_Response( $payload, 200 );
			$response->header( 'Cache-Control', 'public, max-age=600' );
			$response->header( 'ETag', $etag );
			return $response;
		},
	] );
} );

/**
 * The results page ranks by the SAME rule as the suggestions. Without this,
 * "rajma" suggests the product whose keywords say rajma and the results page —
 * WordPress's title/content LIKE search — says no match.
 *
 * Only the main product search on the front end, after WooCommerce's own
 * pre_get_posts (priority 10). An explicit ?orderby= from a sort control is
 * respected; the rank decides only the default order. If the index is empty
 * (WooCommerce not ready), WordPress's own search runs untouched.
 */
add_action( 'pre_get_posts', static function ( WP_Query $q ): void {
	if ( is_admin() || ! $q->is_main_query() || ! $q->is_search() ) {
		return;
	}
	$type = $q->get( 'post_type' );
	if ( 'product' !== $type && [ 'product' ] !== $type ) {
		return;
	}
	$rows = foodify_search_index_rows();
	if ( ! $rows ) {
		return;
	}
	$ids = array_map( static fn( array $r ): int => (int) $r['id'], foodify_search_rank( $rows, (string) $q->get( 's' ), 200 ) );
	$q->set( 'post__in', $ids ?: [ 0 ] );
	if ( empty( $_GET['orderby'] ) ) {   // phpcs:ignore WordPress.Security.NonceVerification -- read-only sort choice.
		$q->set( 'orderby', 'post__in' );
		$q->set( 'order', 'ASC' );
	}
	// Membership is decided; WordPress's LIKE clause would only remove the
	// keyword and typo matches again.
	add_filter( 'posts_search', static function ( string $search, WP_Query $query ) use ( $q ): string {
		return $query === $q ? '' : $search;
	}, 999, 2 );
}, 20 );

/** "No match for ‘xyz’" on the results page, replacing the template's static fallback line. */
add_filter( 'render_block_core/paragraph', static function ( string $html, array $block ): string {
	if ( false === strpos( (string) ( $block['attrs']['className'] ?? '' ), 'fd-search-none' ) || ! is_search() ) {
		return $html;
	}
	$text = foodify_search_none_text( get_search_query( false ) ) . '. Popular right now:';
	return (string) preg_replace( '~(<p\b[^>]*>).*?(</p>)~s', '$1' . esc_html( $text ) . '$2', $html, 1 );
}, 10, 2 );

/** The results page: the query in an open field under the heading (BROWSE-10). */
add_filter( 'render_block_core/query-title', static function ( string $html, array $block ): string {
	if ( 'search' !== ( $block['attrs']['type'] ?? '' ) || ! is_search() ) {
		return $html;
	}
	return $html . foodify_search_results_form_html( get_search_query( false ) ); // Escaped in the builder.
}, 10, 2 );

/** …and no "No products found" above the popular products the page then shows (BROWSE-10). */
add_filter( 'render_block_woocommerce/product-results-count', static function ( string $html ): string {
	global $wp_query;
	$found = $wp_query instanceof WP_Query ? (int) $wp_query->found_posts : 0;
	return foodify_search_hide_results_count( is_search(), $found ) ? '' : $html;
} );

/** Remember that the header form rendered, so the script prints only where there is a field to drive. */
add_filter( 'render_block_core/html', static function ( string $html ): string {
	if ( str_contains( $html, 'data-fd-search' ) ) {
		$GLOBALS['foodify_search_on_page'] = true;
	}
	return $html;
} );

add_action( 'wp_footer', static function (): void {
	if ( empty( $GLOBALS['foodify_search_on_page'] ) ) {
		return;
	}
	echo '<script>window.fdSearchIndexUrl=' . wp_json_encode( esc_url_raw( rest_url( 'foodify/v1/search-index' ) ) ) . ';' . foodify_search_script() . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- static script; the URL is JSON-encoded.
} );
