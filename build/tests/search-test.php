<?php
/**
 * Search: the ranking, written twice (PHP for the results page, JavaScript for
 * the suggestions), and proved to be ONE rule by running the same vectors
 * through both. Plus the header form, which must work with no JavaScript.
 *
 * The rule worth defending by name is parity. If the suggestions say "Rajma
 * Chawal" for "rajma" and the results page says "No match", the customer has
 * been told two different things by one search box.
 *
 *   php tests/search-test.php
 */
declare( strict_types = 1 );

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../theme/foodify/inc/search.php';

$pass = 0; $fail = 0;
function check( string $label, bool $ok ): void {
	global $pass, $fail;
	if ( $ok ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else       { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}
function ids( array $rows ): array {
	return array_map( static fn( $r ) => (int) $r['id'], $rows );
}

// A small catalogue in popularity order. Fixture names, like every test here.
$rows = array_map( 'foodify_search_row', [
	[ 'id' => 1, 'name' => 'Express Dal Fry',     'kw' => 'dal tadka moong arhar',       'stock' => true ],
	[ 'id' => 2, 'name' => 'Rajma Chawal',        'kw' => 'राजमा kidney beans rice',     'stock' => true ],
	[ 'id' => 3, 'name' => 'Veg Biryani',         'kw' => 'basmati rice saffron',       'stock' => true ],
	[ 'id' => 4, 'name' => 'Dalia Upma',          'kw' => 'broken wheat',               'stock' => true ],
	[ 'id' => 5, 'name' => 'Paneer Butter Masala','kw' => 'cottage cheese tomato',      'stock' => false ],
	[ 'id' => 6, 'name' => 'Masala Chai',         'kw' => 'tea ginger elaichi',         'stock' => true ],
	[ 'id' => 7, 'name' => 'Dal Makhani',         'kw' => 'urad rajma butter',          'stock' => false ],
	[ 'id' => 8, 'name' => 'Lemon Poha',          'kw' => 'flattened rice peanuts',     'stock' => true ],
	[ 'id' => 9, 'name' => 'Idli Sambhar',        'kw' => 'rice urad lentil',           'stock' => true ],
] );

echo "── normalisation ──\n";
check( 'lower-case, punctuation to single spaces', 'dal fry 2' === foodify_search_norm( "  DAL-Fry, (2) " ) );
check( 'a Devanagari word keeps its vowel signs', 'राजमा' === foodify_search_norm( 'राजमा!' ) );
check( 'an empty query matches nothing',          [] === foodify_search_rank( $rows, '   ' ) );

echo "── the tiers ──\n";
check( 'tier 0 is the name STARTING with the query ("dal" → Dalia Upma before Express Dal Fry)',
	[ 4, 1, 7 ] === ids( foodify_search_rank( $rows, 'dal' ) ) );
check( 'tier 1: every query word starts a name word ("fry" → Express Dal Fry)', [ 1 ] === ids( foodify_search_rank( $rows, 'fry' ) ) );
check( 'tier 0 before tier 1 ("masala" → Masala Chai, then Paneer Butter Masala)',
	[ 6, 5 ] === ids( foodify_search_rank( $rows, 'masala' ) ) );
check( 'tier 2: a keyword ("moong" → Dal Fry)',   [ 1 ] === ids( foodify_search_rank( $rows, 'moong' ) ) );
check( 'tier 2: a Hindi name ("राजमा" → Rajma Chawal)', [ 2 ] === ids( foodify_search_rank( $rows, 'राजमा' ) ) );
check( 'a name match beats a keyword match ("rajma": Rajma Chawal, then Dal Makhani via its keywords — sold out last anyway)',
	[ 2, 7 ] === ids( foodify_search_rank( $rows, 'rajma' ) ) );
check( 'every query word must match somewhere ("rice saffron" → Biryani only)', [ 3 ] === ids( foodify_search_rank( $rows, 'rice saffron' ) ) );
check( 'tier 3: one typo on a 5+ letter word ("biryni" → Veg Biryani)', [ 3 ] === ids( foodify_search_rank( $rows, 'biryni' ) ) );
check( 'tier 3: a swapped pair counts as one typo ("birayni")', [ 3 ] === ids( foodify_search_rank( $rows, 'birayni' ) ) );
check( 'tier 3: one typo part-way through a word ("biryn")', [ 3 ] === ids( foodify_search_rank( $rows, 'biryn' ) ) );
check( 'BROWSE-9: one typo from 4 letters ("idly" → Idli Sambhar)', [ 9 ] === ids( foodify_search_rank( $rows, 'idly' ) ) );
check( 'BROWSE-9: and "chao" now finds Masala Chai (and Rajma Chawal, "chaw…")', [ 2, 6 ] === ids( foodify_search_rank( $rows, 'chao' ) ) );
check( 'no typo tolerance under 4 letters ("dol" is not "dal")', [] === ids( foodify_search_rank( $rows, 'dol' ) ) );
check( 'a 4-letter word with two slips is still nothing ("idxy")', [] === ids( foodify_search_rank( $rows, 'idxy' ) ) );
check( 'two typos is not a match ("bxryxni")',    [] === ids( foodify_search_rank( $rows, 'bxryxni' ) ) );
check( 'a typo still finds a sold-out product ("panner" → Paneer Butter Masala)', [ 5 ] === ids( foodify_search_rank( $rows, 'panner' ) ) );
check( 'the middle of a word is not a prefix ("neer" finds nothing; one typo cannot reach "paneer")', [] === ids( foodify_search_rank( $rows, 'neer' ) ) );

echo "── sold out goes last ──\n";
$butter = ids( foodify_search_rank( $rows, 'butter' ) );
check( 'both sold-out rows still appear ("butter")', [ 5, 7 ] === $butter );
$ma = ids( foodify_search_rank( $rows, 'ma' ) );
check( '"ma": the in-stock match first, the two sold-out ones after it', [ 6, 5, 7 ] === $ma );
$dalia_gone = $rows;
$dalia_gone[3]['stock'] = false;
check( 'a sold-out tier-0 match trails an in-stock tier-1 match ("dal" with Dalia sold out → Dal Fry first)',
	[ 1, 4, 7 ] === ids( foodify_search_rank( $dalia_gone, 'dal' ) ) );
check( 'the limit holds ("rice" matches four, two asked for)', 4 === count( foodify_search_rank( $rows, 'rice' ) ) && [ 2, 3 ] === ids( foodify_search_rank( $rows, 'rice', 2 ) ) );
check( 'a limit of zero returns nothing',          [] === foodify_search_rank( $rows, 'dal', 0 ) );

echo "── nothing found ──\n";
check( 'popular = the first three IN-STOCK rows', [ 1, 2, 3 ] === ids( foodify_search_popular( $rows ) ) );
$soldout_first = array_merge( [ foodify_search_row( [ 'id' => 9, 'name' => 'Gone', 'stock' => false ] ) ], $rows );
check( 'a sold-out bestseller is never "popular right now"', [ 1, 2, 3 ] === ids( foodify_search_popular( $soldout_first ) ) );
check( 'the words the customer reads', 'No match for ‘xyz’' === foodify_search_none_text( '  xyz ' ) );

echo "── the index ──\n";
$kw = foodify_search_keywords( 'Express Dal Fry', [ 'Arhar, Moong', 'Dal; Express', 'Rice (45%), salt and oil', 'a' ] );
check( 'keywords drop the name\'s own words, digits, stop words and single letters', 'arhar moong rice salt oil' === $kw );
check( 'keywords are capped',                       2 === count( explode( ' ', foodify_search_keywords( 'x', [ 'one two three four' ], 2 ) ) ) );
$row = foodify_search_row( [ 'id' => '12', 'name' => ' Dal ', 'stock' => 1 ] );
check( 'a row is typed (id int, stock bool, name trimmed)', 12 === $row['id'] && true === $row['stock'] && 'Dal' === $row['name'] );
check( 'a row has exactly the fields the browser reads',
	[ 'id', 'name', 'url', 'price', 'prep', 'img', 'kw', 'stock' ] === array_keys( $row ) );
$p1 = foodify_search_payload( $rows );
$p2 = foodify_search_payload( array_reverse( $rows ) );
check( 'the version changes when the rows change (it is the ETag)', $p1['v'] !== $p2['v'] && 12 === strlen( $p1['v'] ) );
check( 'the version is stable for the same rows',   $p1['v'] === foodify_search_payload( $rows )['v'] );

echo "── the header form, which must work with no JavaScript ──\n";
$header = (string) file_get_contents( __DIR__ . '/../theme/foodify/parts/header.html' );
$form   = foodify_search_form_html();
check( 'header.html carries foodify_search_form_html() verbatim, once', 1 === substr_count( $header, $form ) );
check( 'it is inside a core/html block',             1 === preg_match( '~<!-- wp:html -->\s*' . preg_quote( $form, '~' ) . '\s*<!-- /wp:html -->~', $header ) );
check( 'it sits in the header actions, before the account button',
	strpos( $header, 'fd-header__actions' ) < strpos( $header, $form ) && strpos( $header, $form ) < strpos( $header, 'wp:woocommerce/customer-account' ) );
check( 'a plain GET form to the site root',          str_contains( $form, 'method="get" action="/"' ) );
check( 'the field is named s',                       str_contains( $form, 'name="s"' ) );
check( 'it searches products, not posts',            str_contains( $form, '<input type="hidden" name="post_type" value="product">' ) );
check( 'it ships closed (the icon opens it natively)', ! preg_match( '~<details[^>]*\bopen\b~', $form ) );
check( 'the field has a label',                      str_contains( $form, '<label class="fd-sr" for="fd-search-q">' ) && str_contains( $form, 'id="fd-search-q"' ) );
check( 'every icon (open, close, submit) carries words for a screen reader', 3 === substr_count( $form, '<span class="fd-sr">' ) );
check( 'combobox roles are NOT in the static markup (added by the script, so no-JS has no dead popup)',
	! str_contains( $form, 'role="combobox"' ) );
check( 'the listbox exists for the script to fill',  str_contains( $form, 'id="fd-search-list" role="listbox"' ) );
check( 'a live region announces results',            str_contains( $form, 'role="status" aria-live="polite"' ) );

echo "── the results page ──\n";
$tpl = __DIR__ . '/../theme/foodify/templates/product-search-results.html';
$res = is_readable( $tpl ) ? (string) file_get_contents( $tpl ) : '';
check( 'templates/product-search-results.html exists', '' !== $res );
check( 'it uses the shop\'s card loop (fd-products)', str_contains( $res, '"className":"fd-products"' ) );
check( 'the matches come from the main query (inherit:true), which pre_get_posts ranks', str_contains( $res, '"inherit":true' ) );
check( 'it says what was searched for',              str_contains( $res, 'wp:query-title {"type":"search"' ) );
check( 'no results: a static line inc/search.php rewrites', str_contains( $res, 'class="fd-search-none' ) && str_contains( $res, '"className":"fd-search-none"' ) );
check( 'no results: three popular products, in stock', str_contains( $res, '"perPage":3' ) && str_contains( $res, '"orderBy":"popularity"' ) && str_contains( $res, 'instock' ) );
check( 'no numeric attribute id or other install-specific number in the template', ! preg_match( '/"attributeId":[1-9]/', $res ) );

echo "── BROWSE-9: the last suggestion is every match ──\n";
check( '"See all 7 results for ‘dal’"', 'See all 7 results for ‘dal’' === foodify_search_see_all_text( ' dal ', 7 ) );
check( 'one match reads as one', 'See 1 result for ‘idly’' === foodify_search_see_all_text( 'idly', 1 ) );

echo "── BROWSE-10: the results page ──\n";
$again = foodify_search_results_form_html( 'biryni <x>' );
check( 'the query sits in an open, labelled field at the top', str_contains( $again, 'value="biryni &lt;x&gt;"' ) && str_contains( $again, '<label class="fd-search-again__label" for="fd-search-again-q">' ) && str_contains( $again, 'id="fd-search-again-q"' ) );
check( 'a plain GET form for products, no script needed', str_contains( $again, 'method="get" action="/"' ) && str_contains( $again, 'name="post_type" value="product"' ) && str_contains( $again, 'name="s"' ) );
check( 'no ids collide with the header form', ! str_contains( $again, 'id="fd-search-q"' ) );
check( '"No products found" is dropped when the fallback shows', true === foodify_search_hide_results_count( true, 0 ) );
check( 'and kept when there are results, or off a search', false === foodify_search_hide_results_count( true, 3 ) && false === foodify_search_hide_results_count( false, 0 ) );
$ssrc = (string) file_get_contents( __DIR__ . '/../theme/foodify/inc/search.php' );
check( 'both are wired to the template\'s blocks', str_contains( $ssrc, "add_filter( 'render_block_core/query-title'" ) && str_contains( $ssrc, "add_filter( 'render_block_woocommerce/product-results-count'" ) );

echo "── the script ──\n";
$js = foodify_search_script();
foreach ( [
	"setAttribute('role','combobox')"   => 'adds the combobox role',
	'aria-activedescendant'             => 'names the highlighted option',
	"'ArrowDown'"                       => 'ArrowDown moves down',
	"'ArrowUp'"                         => 'ArrowUp moves up',
	"'Escape'"                          => 'Escape closes',
	"e.key==='Enter'&&active>=0"        => 'Enter opens only a highlighted option; otherwise the form submits',
	'No match for ‘'                    => 'says "No match for ‘…’"',
	'FDSearch.popular(rows,3)'          => 'offers three popular products when nothing matches',
	'sessionStorage'                    => 'keeps the index for the session',
	'window.fdSearchRows'               => 'accepts inline rows (the prototype)',
	"credentials:'omit'"                => 'fetches the index without cookies (it is public and cacheable)',
	'textContent=row.name'              => 'writes product names as text, never as HTML',
	'FDSearch.seeAll(q,all)'            => 'ends the list with "See all N results" (BROWSE-9)',
	'opts=opts.concat([{url:href}])'    => 'so Enter on it opens the results page',
] as $needle => $label ) {
	check( 'the script ' . $label, str_contains( $js, $needle ) );
}
check( 'the script never builds markup from data with innerHTML', ! str_contains( $js, 'innerHTML' ) );

echo "── PHP and JavaScript rank identically ──\n";
$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
check( 'node is available to run the JavaScript half', '' !== $node );
if ( '' !== $node ) {
	$queries = [ 'dal', 'fry', 'masala', 'moong', 'राजमा', 'rajma', 'rice saffron', 'biryni', 'birayni', 'biryn', 'chao',
		'bxryxni', 'panner', 'aneer', 'neer', 'butter', 'ma', 'a', 'RICE', 'dal  f', 'chai-masala', 'po', 'lemon poha', 'xyz', ' ',
		// BROWSE-9: the 4-letter typo rule, both engines.
		'idly', 'idli', 'idxy', 'dol', 'pohq', 'upma', 'sambar', 'tex', 'teaa', 'rce' ];
	$dir = sys_get_temp_dir() . '/fd-search-' . getmypid();
	@mkdir( $dir );
	file_put_contents( $dir . '/engine.js', foodify_search_engine_js() . "\nmodule.exports=FDSearch;\n" );
	file_put_contents( $dir . '/vectors.json', json_encode( [ 'rows' => $rows, 'queries' => $queries ], JSON_UNESCAPED_UNICODE ) );
	file_put_contents( $dir . '/run.js', "const S=require('./engine.js');const v=require('./vectors.json');"
		. "const out={};for(const q of v.queries){out[q]={rank:S.rank(v.rows,q,6).map(r=>r.id),two:S.rank(v.rows,q,2).map(r=>r.id),norm:S.norm(q),all:S.seeAll(q,S.rank(v.rows,q,200).length)};}"
		. "out.__popular=S.popular(v.rows,3).map(r=>r.id);process.stdout.write(JSON.stringify(out));" );
	$raw = (string) shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( $dir . '/run.js' ) . ' 2>&1' );
	$js_out = json_decode( $raw, true );
	check( 'the JavaScript engine ran', is_array( $js_out ) );
	if ( is_array( $js_out ) ) {
		$same = 0;
		foreach ( $queries as $q ) {
			$php = [ 'rank' => ids( foodify_search_rank( $rows, $q, 6 ) ), 'two' => ids( foodify_search_rank( $rows, $q, 2 ) ), 'norm' => foodify_search_norm( $q ),
				'all' => foodify_search_see_all_text( $q, count( foodify_search_rank( $rows, $q, 200 ) ) ) ];
			$ok  = $php === ( $js_out[ $q ] ?? null );
			if ( ! $ok ) {
				check( sprintf( 'same answer for %s — PHP %s, JS %s', json_encode( $q, JSON_UNESCAPED_UNICODE ), json_encode( $php ), json_encode( $js_out[ $q ] ?? null ) ), false );
			} else {
				$same++;
			}
		}
		check( sprintf( 'all %d queries rank identically in PHP and JavaScript (ids, order, limit, normalisation)', count( $queries ) ), count( $queries ) === $same );
		check( 'popular products agree', ids( foodify_search_popular( $rows, 3 ) ) === ( $js_out['__popular'] ?? null ) );
	}
	file_put_contents( $dir . '/whole.js', $js );
	exec( escapeshellarg( $node ) . ' --check ' . escapeshellarg( $dir . '/whole.js' ) . ' 2>&1', $o, $code );
	check( 'the whole header script parses', 0 === $code );
	array_map( 'unlink', glob( $dir . '/*' ) ?: [] );
	@rmdir( $dir );
}

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
