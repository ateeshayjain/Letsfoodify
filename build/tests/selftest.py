#!/usr/bin/env python3
"""
Self-test for scripts/smoke-test.sh.

A blocking gate nobody has tested is not a gate. This serves three
WooCommerce-shaped fixture sites and asserts what smoke-test.sh reports:

  1. healthy       -> the honesty checks report clean, exit 0
  2. defective     -> the leaked comment and fake counter are CAUGHT, exit non-zero
  3. unreachable   -> the gate must NOT report the defects as absent

Case 3 is the regression test. Every "must be gone" assertion is an absence
check, and grep over an empty body reports absence — so a dead site scores a
clean bill of health unless the body is checked first.

Run:  python3 tests/selftest.py            (tests the current gate)
      GATE=scripts/smoke-test.sh.orig python3 tests/selftest.py   (proves the bug)
"""
import http.server, socketserver, subprocess, sys, threading, os, datetime, re

ANSI = re.compile(r"\x1b\[[0-9;]*m")

YEAR = datetime.date.today().year
HEAD = ('<!doctype html><html><head>'
        '<meta name="description" content="Instant home-style Indian meals, ready in 6 minutes">'
        '<meta property="og:title" content="The Foodify Company">'
        '<meta name="twitter:card" content="summary">'
        '<link rel="stylesheet" href="/a.css"><script src="/a.js"></script>'
        '<script type="application/ld+json">{"@type": "Product","name":"Express Dal Fry"}</script>'
        '</head><body>')
FOOT = f"<footer>&copy; {YEAR} The Foodify Company</footer></body></html>"
PAD  = "<p>" + ("Home-style Indian food, gently dried. " * 30) + "</p>"

CHECKOUT_FORM = """
 <input name="billing_first_name"><input name="billing_phone">
 <input name="billing_email" required><input name="billing_postcode">
 <input name="billing_city">
 <select name="billing_state" id="billing_state"><option value="UP">Uttar Pradesh</option></select>
 <input name="billing_address_1"><input name="billing_address_2">
 <input name="shipping_first_name"><input name="shipping_postcode">
 <li class="payment_method_cod">Cash on delivery</li>
 <li class="payment_method_razorpay">Razorpay</li>"""


def pages(mode):
    leak    = "// 3. Inject JS step-switching script to show only one box at a time" if mode == "bad" else ""
    counter = "<span>70 people are viewing this right now</span>" if mode == "bad" else ""
    return {
        "/": HEAD + "<h1>A real meal in six minutes</h1>" + PAD +
             '<a href="/product/express-dal-fry/">Dal Fry</a>' + FOOT,
        "/product/express-dal-fry/": HEAD + "<h1>Express Dal Fry</h1>" + counter + PAD + FOOT,
        "/shop/": HEAD + "<h1>Shop</h1>" + PAD + '<a href="/?add-to-cart=42">Add</a>' + FOOT,
        "/cart/": HEAD + "<h1>Cart</h1>" + PAD + "<p>1 item in your cart</p>" + FOOT,
        # WP-06: the good build runs a stripped checkout header. The bad one
        # runs the full site chrome, where every nav link is an exit.
        "/checkout/": HEAD + ('<nav class="wp-block-navigation"><a href="/shop/">Shop</a></nav>'
                              if mode == "bad" else
                              '<p class="fd-checkout-back"><a href="/cart/">Back to cart</a></p>')
                      + "<h1>Checkout</h1>" + PAD + CHECKOUT_FORM + FOOT,
        "/my-account/": HEAD + "<h1>My account</h1>" + leak + PAD + FOOT,
        # WP-05's address book is a rewrite endpoint. The "bad" fixture omits it
        # so the 404 the gate must catch is a real 404, not an asserted string.
        **({} if mode == "bad" else
           {"/my-account/address-book/": HEAD + "<h1>Sign in</h1>" + PAD + FOOT}),
        "/robots.txt": "User-agent: *\nSitemap: /sitemap_index.xml\n",
        "/sitemap_index.xml": '<?xml version="1.0"?><sitemapindex></sitemapindex>',
    }


def serve(mode, port):
    P = pages(mode)

    class H(http.server.BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        def do_GET(self):
            path = self.path.split("?")[0]
            if path == "/wp-sitemap.xml":          # retired by Rank Math
                self.send_error(404); return
            body = P.get(path)
            if body is None:
                self.send_error(404); return
            b = body.encode()
            self.send_response(200)
            self.send_header("Content-Type", "text/plain" if path.endswith(".txt") else "text/html")
            self.send_header("Content-Length", str(len(b)))
            self.send_header("Set-Cookie", "woocommerce_items_in_cart=1; Path=/")
            # WP-06 privacy headers. The "good" fixture sends them; the "bad"
            # one omits them AND reports an edge cache HIT, which is the actual
            # failure being defended against — one customer's checkout page
            # served to another.
            if path in ("/cart/", "/checkout/", "/my-account/", "/my-account/address-book/"):
                if mode == "bad":
                    self.send_header("X-Cache", "HIT")
                else:
                    self.send_header("Cache-Control",
                                     "no-cache, no-store, must-revalidate, max-age=0, private")
                    self.send_header("X-Foodify-Private", "1")
                    self.send_header("X-Cache", "BYPASS")
            self.end_headers()
            self.wfile.write(b)

        def log_message(self, *a):
            pass

    socketserver.TCPServer.allow_reuse_address = True
    srv = socketserver.ThreadingTCPServer(("127.0.0.1", port), H)
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    return srv


# Resolve relative to this file, not the caller's cwd — the test has to work from
# the repo root, from build/, and from tests/ alike.
KIT  = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))   # .../build
GATE = os.environ.get("GATE", "scripts/smoke-test.sh")
if not os.path.isabs(GATE):
    GATE = os.path.join(KIT, GATE)
if not os.path.isfile(GATE):
    sys.exit(f"Gate not found: {GATE}")
G, R = "\033[32m", "\033[0m"
RED, YEL = "\033[31m", "\033[33m"
passed = failed = 0


def check(label, cond):
    global passed, failed
    if cond:
        print(f"  {G}PASS{R} {label}"); passed += 1
    else:
        print(f"  {RED}FAIL{R} {label}"); failed += 1


def run(port):
    env = dict(os.environ); env.pop("HTTPS_PROXY", None); env.pop("http_proxy", None)
    env["NO_PROXY"] = "*"
    p = subprocess.run(["bash", GATE, f"http://127.0.0.1:{port}"],
                       capture_output=True, text=True, timeout=120, env=env, cwd=KIT)
    # Strip colour before matching, or "PASS" and its label are never adjacent.
    return ANSI.sub("", p.stdout + p.stderr), p.returncode


print(f"Gate under test: {GATE}\n")

print("── Case 1 · healthy site ──")
s1 = serve("good", 8971)
out, rc = run(8971); s1.shutdown()
check("reports no leaked source comment", "PASS no leaked source comment" in out)
check("reports no fake viewer counter",   "PASS no fake viewer counter" in out)
check("core sitemap retired",             "core sitemap retired" in out)
check("billing fields within budget",     "billing fields: 8" in out)
check("COD detected",                     "PASS COD offered" in out)
check("address-book endpoint reachable",  "PASS /my-account/address-book/ \u2192 200" in out)
check("checkout sends no-store",          "PASS /checkout/ sends Cache-Control: no-store" in out)
check("private-page marker seen",         "PASS /checkout/ carries the theme's private-page marker" in out)
check("edge reports not cached",          "PASS /checkout/ reported by the edge as not cached" in out)
check("stripped checkout header",         "PASS checkout uses the stripped header" in out)
check("no site nav on checkout",          "PASS no site navigation on checkout" in out)
check("exits 0",                          rc == 0)

print("\n── Case 2 · site carrying the audit's defects ──")
s2 = serve("bad", 8972)
out, rc = run(8972); s2.shutdown()
check("leaked comment CAUGHT",  "developer comment still leaking" in out)
check("fake counter CAUGHT",    "fake viewer counter still present" in out)
check("missing address-book endpoint CAUGHT", "rewrite rules not flushed" in out)
# The one that matters most: a cached checkout page shares one customer's
# details with the next visitor. The gate must call that a failure, not a win.
check("cached checkout page CAUGHT",  "served FROM AN EDGE CACHE (HIT)" in out)
check("missing no-store CAUGHT",      "does NOT send no-store" in out)
check("missing theme marker CAUGHT",  "the WP-06 module is not running here" in out)
check("full site chrome on checkout CAUGHT", "checkout is NOT using the stripped header" in out)
check("exits non-zero",         rc != 0)

print("\n── Case 3 · unreachable site (the regression test) ──")
out, rc = run(8973)   # nothing listening
check("does NOT falsely clear the leaked comment",
      "PASS no leaked source comment" not in out)
check("does NOT falsely clear the fake counter",
      "PASS no fake viewer counter" not in out)
check("does NOT falsely clear the address-book endpoint",
      "endpoint registered" not in out)
check("does NOT falsely clear the cache policy",
      "sends Cache-Control: no-store" not in out)
check("says the cache policy could not be verified",
      "cache policy NOT verified" in out)
check("does NOT falsely clear the checkout header",
      "PASS no site navigation on checkout" not in out)
check("says the page did not load", "did not load" in out)
check("exits non-zero", rc != 0)

print(f"\n  {passed} passed · {failed} failed")
sys.exit(1 if failed else 0)
