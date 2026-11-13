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

## What a blank install showed (13 Nov 2026)

The client's developer uploaded the zip to an empty WordPress through wp-admin
and reported "file is no good, many things missing". Reproduced here, the home
page showed:

| Seen | Cause | Now |
|---|---|---|
| Headline and "How it works" button invisible | A cover block's text is white unless it declares a light overlay; the prototype does not load WordPress's block CSS, so it showed them dark | `"isDark":false` + `.is-light` on the hero |
| `[foodify_google_reviews limit="3"]` printed as text | WordPress runs shortcodes over a template BEFORE patterns are expanded, so a shortcode inside a pattern never runs | `render_block_core/shortcode` filter runs it |
| "Hello world!" on the Bestsellers shelf, three headings over nothing | No WooCommerce → no `product` post type → the loop block fell back to posts | A shelf matches nothing without products, and an empty shelf is hidden |
| "What customers say" over an empty box | Reviews unconfigured | The section is hidden until there is a review |
| Blank theme card in Appearance → Themes | No `screenshot.png` | Added (`tools/theme-screenshot.js`) |

`scripts/wp-render-test.sh` now opens the real page in a browser at two widths
and fails on any of these; `package-theme.sh` runs it on the zip itself.

**Still true, and not fixed by any of this:** WooCommerce is not installed in
this WordPress, so the shop, product, cart, checkout and account pages have
never been rendered by real WooCommerce here — only by the prototype. They need
a staging pass before anyone calls them verified. And a blank WordPress has no
products, pages or settings: those come from the live shop's database and
`scripts/bootstrap.sh` (WP-CLI), neither of which a wp-admin upload provides.

### Later the same day: the setup screen, and a fatal

Building **Appearance → Foodify setup** (`inc/setup.php`) meant opening the
inner pages in that blank WordPress for the first time. Every one of them — any
page, post, search or 404 — was a **blank page with a 200**: `business-profile.php`
called WooCommerce's `is_shop()` unguarded, a fatal wherever `is_front_page()`
was false. Only the home page had ever been opened without WooCommerce.

`scripts/wp-render-test.sh` now runs the setup screen's page step for real
(twice — the second run must create nothing), then opens the home page, every
page the header and footer link to, cart, checkout, a 404 and the setup screen
itself, and fails on an unfinished document, a missing H1 or raw code.
`tests/blank-install-test.php` fails, by file and line, on any WooCommerce page
test called without asking that it exists.

Also found by that crawl: WordPress ships its Privacy Policy page as a DRAFT,
so the footer link 404s on a new site. The setup screen reports it and leaves
publishing to a person — it never publishes or rewrites a page it did not make.

---

## With WooCommerce (13 Nov 2026)

The sandbox that could not reach wordpress.org was replaced by one that can, and
the theme was rendered by a real WooCommerce for the first time. **It found 34
defects, 2 of them on every page of a section** (every My account page showed
the cart; the checkout had no layout at all). All of it — what was seen, the
cause, the fix, and what is still unverified — is in
**`docs/WOO-RENDER-2026-11-13.md`**. Read that before trusting any shop screen.

### Building the two sites

One script builds both, from `downloads.wordpress.org` (WordPress core, the
SQLite plugin, WooCommerce — all free, all wordpress.org's own):

```bash
build/scripts/wp-site-build.sh /home/user/wpsite              # WordPress only — wp-boot-test, wp-render-test, run-all-tests.sh
build/scripts/wp-site-build.sh /home/user/wpsite-woo --woo    # + WooCommerce — wp-woo-test
mkdir -p /tmp/node_modules && ln -sfn "$(npm root -g)/playwright" /tmp/node_modules/playwright-core   # where the tools look for Playwright
```

An existing directory is left alone; delete it to rebuild. The manual `git
clone` recipe above still works where only github.com is reachable — but it
cannot fetch WooCommerce (a monorepo that needs a build), which is why the
shop pages went unrendered for so long.

WooCommerce runs on SQLite here: its installer created all its tables, and
products, the Store API cart, classic checkout, orders and the account all
worked. **It has not been run on MySQL/MariaDB** — which is what the live site
uses. Orders in the check are stored in WooCommerce's HPOS tables (its default
on a new install); the live shop may be on either. Both are staging checks.

### The check

```bash
build/scripts/wp-woo-test.sh                                   # 118 checks, about three minutes
FOODIFY_WOO_SHOTS=/tmp/shots build/scripts/wp-woo-test.sh      # + a screenshot of every page
FOODIFY_THEME_SRC=/path/to/unzipped/foodify build/scripts/wp-woo-test.sh
```

It puts the database back to "a WooCommerce nobody has set up", activates the
theme, then goes through the site as a person does (`tools/wp-woo-check.js`):
Appearance → Foodify setup **by its buttons** (checking the database after
each, and that a second run changes nothing), a delivery zone and going Live,
every page type at 1280 and 390, Add on a card, the cart's steppers and a
coupon, a guest order and a signed-in order through the real checkout, the
account, Order again, removing the samples — and finally the PHP error log.

**It is not in `run-all-tests.sh`**, on purpose: that gate must stay runnable
where WooCommerce cannot be downloaded. Run this one where it can — before a
hand-over, and after any change to a template, `style.css` or `inc/`. With no
WooCommerce, no browser or no server it prints **"did NOT run"** and exits 2;
it was also run against the theme as it stood on `main` before the fixes, and
failed about sixty checks.

It proves what it clicks. It does not prove a payment gateway, an SMS sign-in,
an email arriving, MySQL, or a real phone — the findings document lists
every one of those.
