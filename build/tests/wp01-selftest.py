#!/usr/bin/env python3
"""
Self-test for scripts/wp01-verify.sh.

WP-01 ships to a live store. Its gate gets tested before it is trusted, against
four fixture sites:

  1. post-WP-01   every criterion met                 -> exit 0
  2. pre-WP-01    the audit's defects still present   -> caught, exit 1
  3. noindexed    catalogue accidentally noindexed    -> caught LOUDLY, exit 1
  4. dead         nothing listening                   -> must NOT report the
                  defects as absent, exit 1

Case 3 is the one that matters. The Rank Math option blob that noindexes 170 tag
archives also controls index on 44 products, and a wrong key looks correct in
wp-admin. If the gate cannot catch that, it is not protecting anything.

Run:  python3 tests/wp01-selftest.py
"""
import http.server, socketserver, subprocess, sys, threading, os, datetime, re

ANSI = re.compile(r"\x1b\[[0-9;]*m")
YEAR = datetime.date.today().year
PROD_SLUG = "express-dal-fry"


def pages(mode, port):
    base = f"http://127.0.0.1:{port}"
    leak    = "// 3. Inject JS step-switching script to show only one box at a time" if mode == "pre" else ""
    counter = "<span>70 people are viewing this right now</span>" if mode == "pre" else ""
    # pre-WP-01: no SEO plugin, so no description, no og tags, no reviews tab
    seo = "" if mode == "pre" else (
        '<meta name="description" content="Moong and toor dal, ghee-tempered, ready in six minutes.">'
        '<meta property="og:title" content="Express Dal Fry">'
        '<meta property="og:description" content="Ready in six minutes.">'
        '<meta property="og:image" content="/i.jpg">'
        '<meta name="twitter:card" content="summary_large_image">')
    prod_robots = '<meta name="robots" content="noindex, follow">' if mode == "noindexed" \
                  else '<meta name="robots" content="index, follow">'
    reviews = "" if mode == "pre" else (
        '<div id="reviews" class="woocommerce-Reviews"><h2>Reviews</h2>'
        '<p class="comment-form-rating">Your rating</p></div>')
    pad = "<p>" + ("Home-style Indian food, gently dried. " * 30) + "</p>"
    head = f"<!doctype html><html><head>{seo}"

    return {
        "/": head + f'{prod_robots}</head><body><h1>Six minutes</h1>{pad}'
             f'<a href="{base}/product/{PROD_SLUG}/">Dal Fry</a>'
             f'<footer>&copy; {YEAR} The Foodify Company</footer></body></html>',
        f"/product/{PROD_SLUG}/": head + f'{prod_robots}</head><body><h1>Express Dal Fry</h1>'
             f'{counter}{pad}<a href="{base}/product-tag/quick-dinner/">Quick Dinner</a>'
             f'{reviews}</body></html>',
        "/product-tag/quick-dinner/":
             '<!doctype html><html><head><meta name="robots" content="'
             + ('index, follow' if mode == "pre" else 'noindex, follow')
             + f'"></head><body><h1>Quick Dinner</h1>{pad}</body></html>',
        "/my-account/": head + '</head><body><h1>My account</h1>' + leak + pad + '</body></html>',
        "/shop/": head + f'</head><body><h1>Shop</h1>{pad}<a href="{base}/product/{PROD_SLUG}/">Dal Fry</a></body></html>',
        "/sitemap_index.xml": '<?xml version="1.0"?><sitemapindex>'
             f'<sitemap><loc>{base}/product-sitemap.xml</loc></sitemap></sitemapindex>',
        "/product-sitemap.xml": '<?xml version="1.0"?><urlset>'
             f'<url><loc>{base}/product/{PROD_SLUG}/</loc></url></urlset>',
    }


def serve(mode, port):
    P = pages(mode, port)

    class H(http.server.BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        def do_GET(self):
            path = self.path.split("?")[0]
            # Core sitemap: still served before WP-01, retired after.
            if path == "/wp-sitemap.xml":
                if mode == "pre":
                    b = b'<?xml version="1.0"?><sitemapindex></sitemapindex>'
                    self.send_response(200)
                    self.send_header("Content-Type", "application/xml")
                    self.send_header("Content-Length", str(len(b)))
                    self.end_headers(); self.wfile.write(b); return
                self.send_error(404); return
            # Before WP-01 there is no Rank Math sitemap.
            if path == "/sitemap_index.xml" and mode == "pre":
                self.send_error(404); return
            body = P.get(path)
            if body is None:
                self.send_error(404); return
            b = body.encode()
            self.send_response(200)
            self.send_header("Content-Type", "application/xml" if path.endswith(".xml") else "text/html")
            self.send_header("Content-Length", str(len(b)))
            self.end_headers(); self.wfile.write(b)

        def log_message(self, *a):
            pass

    socketserver.TCPServer.allow_reuse_address = True
    srv = socketserver.ThreadingTCPServer(("127.0.0.1", port), H)
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    return srv


KIT  = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
GATE = os.environ.get("GATE", "scripts/wp01-verify.sh")
if not os.path.isabs(GATE):
    GATE = os.path.join(KIT, GATE)

G, R, RED = "\033[32m", "\033[0m", "\033[31m"
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
    return ANSI.sub("", p.stdout + p.stderr), p.returncode


print(f"Gate under test: {GATE}\n")

print("── Case 1 · WP-01 complete ──")
s = serve("post", 8981); out, rc = run(8981); s.shutdown()
check("no leaked comment",            "PASS /my-account/ carries no leaked source comment" in out)
check("core sitemap retired",         "PASS core wp-sitemap.xml retired" in out)
check("Rank Math sitemap serving",    "PASS Rank Math sitemap_index.xml serving" in out)
check("reviews tab present",          "PASS product page renders a reviews tab" in out)
check("no fake counter",              "PASS no fake viewer counter" in out)
check("product indexable",            "PASS product page is indexable" in out)
check("tag archive noindex",          "PASS tag archive is noindex" in out)
check("all products carry meta",      "products carry a description and 4+ social tags" in out)
check("footer year current",          "PASS footer year current" in out)
check("exits 0",                      rc == 0)

print("\n── Case 2 · WP-01 not done — every defect still live ──")
s = serve("pre", 8982); out, rc = run(8982); s.shutdown()
check("leaked comment CAUGHT",        "still leaks the developer comment" in out)
check("fake counter CAUGHT",          "FAIL fake viewer counter still present" in out)
check("core sitemap CAUGHT",          "core wp-sitemap.xml still served" in out)
check("missing reviews tab CAUGHT",   "no reviews tab" in out)
check("indexable tag archive CAUGHT", "tag archive is still indexable" in out)
check("exits non-zero",               rc != 0)

print("\n── Case 3 · catalogue accidentally noindexed (the dangerous one) ──")
s = serve("noindexed", 8983); out, rc = run(8983); s.shutdown()
check("PRODUCT noindex CAUGHT",  "PRODUCT PAGE IS NOINDEX" in out)
check("HOMEPAGE noindex CAUGHT", "HOMEPAGE IS NOINDEX" in out)
check("says roll back, not fix forward", "roll back" in out)
check("exits non-zero",          rc != 0)

print("\n── Case 4 · unreachable site ──")
out, rc = run(8984)
check("does NOT clear the leaked comment", "PASS /my-account/ carries no leaked source comment" not in out)
check("does NOT clear the fake counter",   "PASS no fake viewer counter" not in out)
check("does NOT call 000 a retired sitemap", "PASS core wp-sitemap.xml retired" not in out)
check("says it could not verify",          "cannot verify" in out or "did not load" in out)
check("exits non-zero",                    rc != 0)

print(f"\n  {passed} passed · {failed} failed")
sys.exit(1 if failed else 0)
