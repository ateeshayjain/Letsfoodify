#!/usr/bin/env bash
# Load the theme into a REAL WordPress and report every PHP diagnostic it causes.
#
# WHY THIS EXISTS
# ---------------
# For ten work packages this project verified the theme with `php -l` and pure
# unit tests, and both are blind to the same class of defect: `php -l` proves a
# file PARSES, not that it RUNS. `foodify_attributed_coupons()` was called twice
# and defined nowhere for weeks with every check green (WP-09).
#
# The first run of this script found a second one immediately — the theme was
# loading translations before `init`, which is a notice on every page load with
# WP_DEBUG on and a silently-unloaded text domain with it off. No static check
# could see it.
#
# WordPress runs on SQLite here (WordPress/sqlite-database-integration), so this
# needs no MySQL. WooCommerce is NOT installed — its repo is a monorepo that
# needs a build — so this covers the WordPress half of the theme. That is a real
# limit and it is stated rather than glossed.
#
#   scripts/wp-boot-test.sh
set -uo pipefail
KIT="$(cd "$(dirname "$0")/.." && pwd)"
WP="${FOODIFY_WP_DIR:-/home/user/wpsite}"
G="\033[32m"; R="\033[31m"; Y="\033[33m"; N="\033[0m"

# A MISSING WORDPRESS MUST NOT LOOK LIKE A PASS. Every other gate in this
# project has been bitten by an absence check that could not run, so this exits
# non-zero and says why rather than printing nothing and returning 0.
if [[ ! -f "$WP/wp-load.php" ]]; then
  printf "${R}  SKIP-FAIL${N} no WordPress at %s — this gate did NOT run\n" "$WP"
  printf "            set FOODIFY_WP_DIR, or see docs/WP-BOOT.md to build one\n"
  exit 2
fi

rm -rf "$WP/wp-content/themes/foodify"
cp -r "$KIT/theme/foodify" "$WP/wp-content/themes/foodify" || { printf "${R}  FAIL${N} could not stage the theme\n"; exit 2; }

cat > "$WP/foodify-boot.php" <<'PHP'
<?php
$problems = [];
set_error_handler( function ( $no, $str, $file, $line ) use ( &$problems ) {
    if ( str_contains( (string) $file, 'themes/foodify' ) || str_contains( (string) $str, 'foodify' ) ) {
        $problems[] = trim( strip_tags( (string) $str ) ) . '  @ ' . basename( (string) $file ) . ':' . $line;
    }
    return true;
}, E_ALL );

require_once __DIR__ . '/wp-load.php';
switch_theme( 'foodify' );

$fail = 0;
$ok   = function ( string $label, bool $cond ) use ( &$fail ): void {
    printf( $cond ? "  \033[32mPASS\033[0m %s\n" : "  \033[31mFAIL\033[0m %s\n", $label );
    if ( ! $cond ) { $GLOBALS['boot_fail'] = ( $GLOBALS['boot_fail'] ?? 0 ) + 1; }
};

$theme = wp_get_theme( 'foodify' );
$ok( 'WordPress accepts the theme (no theme errors)', ! $theme->errors() );
$ok( 'WordPress treats it as a BLOCK theme', wp_is_block_theme() );

$css = WP_Theme_JSON_Resolver::get_merged_data()->get_stylesheet();
$ok( sprintf( 'theme.json resolves (%d bytes of CSS)', strlen( $css ) ), strlen( $css ) > 5000 );
foreach ( [ 'flame', 'kraft-pale', 'leaf-ink', 'char' ] as $c ) {
    $ok( "colour token --wp--preset--color--$c exists", str_contains( $css, "--wp--preset--color--$c" ) );
}

foreach ( glob( get_stylesheet_directory() . '/{templates,parts}/*.html', GLOB_BRACE ) as $f ) {
    $blocks = parse_blocks( file_get_contents( $f ) );
    $named  = 0;
    array_walk_recursive( $blocks, function ( $v, $k ) use ( &$named ) { if ( 'blockName' === $k && $v ) { $named++; } } );
    $ok( sprintf( '%-28s parses (%d blocks)', basename( $f ), $named ), $named > 0 );
}

// The token substitution WP-06 and WP-08 both claim. Asserted against what
// WordPress actually renders, not against the source.
$rendered = do_blocks( file_get_contents( get_stylesheet_directory() . '/parts/footer.html' ) );
$ok( 'no un-replaced FOODIFY_ token survives rendering', ! preg_match( '/<!--FOODIFY_[A-Z]+-->/', $rendered ) );
$ok( 'the copyright year renders', str_contains( $rendered, wp_date( 'Y' ) ) );

$role = get_role( 'foodify_shop_staff' );
$ok( 'the Shop Staff role exists', (bool) $role );
if ( $role ) {
    $bad = foodify_granted_forbidden_caps( (array) $role->capabilities );
    $ok( 'Shop Staff holds no forbidden capability' . ( $bad ? ' (' . implode( ', ', $bad ) . ')' : '' ), ! $bad );
    $ok( 'Shop Staff can work orders', $role->has_cap( 'edit_shop_orders' ) );
}

global $wp_rewrite;
$ok( "the address-book endpoint is registered", in_array( 'address-book', array_column( (array) $wp_rewrite->endpoints, 1 ), true ) );

restore_error_handler();
$problems = array_values( array_unique( $problems ) );
$ok( sprintf( 'the theme raises no PHP diagnostic (%d found)', count( $problems ) ), ! $problems );
foreach ( $problems as $p ) { printf( "        %s\n", $p ); }

exit( ( $GLOBALS['boot_fail'] ?? 0 ) > 0 ? 1 : 0 );
PHP

php -d display_errors=0 -d error_reporting=0 "$WP/foodify-boot.php" 2>/dev/null
RC=$?
rm -f "$WP/foodify-boot.php"
[[ $RC -eq 0 ]] && printf "\n${G}  WordPress boot: clean${N}\n" || printf "\n${R}  WordPress boot: FAILED${N}\n"
exit $RC
