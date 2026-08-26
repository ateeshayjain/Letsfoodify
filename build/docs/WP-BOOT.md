# Running the theme in a real WordPress

For ten work packages this project verified the theme with `php -l` and pure
unit tests. **Both are blind to the same class of defect**: `php -l` proves a
file *parses*, not that it *runs*. `foodify_attributed_coupons()` was called
twice and defined nowhere for weeks, with every check green (WP-09).

`scripts/wp-boot-test.sh` loads the theme into an actual WordPress and reports
every PHP diagnostic it causes. **The first run found a second defect
immediately** — see below.

## Running it

```bash
build/scripts/wp-boot-test.sh          # uses /home/user/wpsite
FOODIFY_WP_DIR=/path/to/wp build/scripts/wp-boot-test.sh
```

A missing WordPress **exits 2 and says the gate did not run**. It does not print
nothing and return 0 — every other gate in this project has been bitten by an
absence check that could not run.

## Building the environment

No MySQL needed: WordPress runs on SQLite via WordPress's own
`sqlite-database-integration`.

```bash
git clone --depth 1 https://github.com/WordPress/WordPress          ~/wordpress/wordpress
git clone --depth 1 https://github.com/WordPress/sqlite-database-integration ~/wordpress/sqlite-db

WP=~/wpsite; P=~/wordpress/sqlite-db
mkdir -p "$WP" && cp -r ~/wordpress/wordpress/. "$WP"/ && rm -rf "$WP/.git"

mkdir -p "$WP/wp-content/plugins/sqlite-database-integration"
cp -r "$P/packages/plugin-sqlite-database-integration/." "$WP/wp-content/plugins/sqlite-database-integration/"

# The plugin ships wp-includes/database as a SYMLINK relative to its own repo.
# Copied out of that repo the link dangles, and WordPress dies with a bare
# "critical error" that names nothing. Replace it with the real directory.
rm -f "$WP/wp-content/plugins/sqlite-database-integration/wp-includes/database"
cp -r "$P/packages/mysql-on-sqlite/src" \
      "$WP/wp-content/plugins/sqlite-database-integration/wp-includes/database"

cp "$P/packages/plugin-sqlite-database-integration/db.copy" "$WP/wp-content/db.php"
sed -i "s#'{SQLITE_IMPLEMENTATION_FOLDER_PATH}'#__DIR__.'/plugins/sqlite-database-integration'#g" "$WP/wp-content/db.php"
sed -i "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" "$WP/wp-content/db.php"
```

Then a `wp-config.php` with any DB constants (SQLite ignores them), `WP_DEBUG`
on, and:

```php
php -r 'define("WP_INSTALLING",true); require "'"$WP"'/wp-load.php";
        require ABSPATH."wp-admin/includes/upgrade.php";
        wp_install("Foodify Staging","admin","admin@example.org",true,"","pass");'
```

## What it proves, and what it does not

**Proves** — verified 26 Aug 2026, 28 assertions:

- WordPress accepts the theme with no theme errors
- `wp_is_block_theme()` is **true** — the WP-03 architectural decision, confirmed
  by WordPress rather than asserted by me
- `theme.json` resolves to ~21 KB of CSS with the colour tokens present
- all 14 templates and parts parse into blocks
- **`do_blocks()` leaves no un-replaced `<!--FOODIFY_*-->` token** and renders the
  real year — the WP-06 and WP-08 claims, checked against output rather than source
- the Shop Staff role is created holding **none** of the forbidden capabilities —
  the WP-10 claim, checked in the database rather than in the array
- the address-book rewrite endpoint is registered — the WP-05 claim
- the theme raises **zero** PHP diagnostics

**Does not prove.** WooCommerce is not installed — its repo is a monorepo needing
a JS build, and wordpress.org is unreachable from this environment. So everything
behind `function_exists( 'WC' )` is still unexercised: the checkout field trim,
the payment fee, the coupon ledger's SQL, the address book's WooCommerce
endpoints. **The pure tests carry that half, and a real staging site is still the
only thing that will close it.** WP-00 remains the gate.

---

## What it found on the first run

```
Notice: Function _load_textdomain_just_in_time was called incorrectly.
Translation loading for the foodify domain was triggered too early.
```

Traced to `inc/product-attributes.php:78`, which registered its taxonomy filters
at **file-load time** by calling `foodify_attribute_map()` — a map full of
`__()` calls. That runs when `functions.php` requires the file, long before
`init`.

Two consequences, and **the quiet one is worse**. With `WP_DEBUG` on it is a
notice on every page load. With it off it is silent, and the translations for
this domain may simply never load, because they were asked for before WordPress
was ready to provide them.

The registration only ever needed the **slugs**, so it now reads
`foodify_attribute_slugs()` and the translated map is left to the render path.
The cost is two lists that must agree — and two lists that must agree is exactly
what this project keeps finding out of step, so `tests/product-spec-test.php`
pins them equal.

No static check could have found this. It took a WordPress boot, which this
project had never done until today.
