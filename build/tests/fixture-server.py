#!/usr/bin/env python3
"""Minimal WooCommerce-shaped fixture server for smoke-test self-testing.
   Usage: fixture-server.py <port> <good|bad>"""
import sys, http.server, socketserver

MODE = sys.argv[2]
YEAR = __import__('datetime').date.today().year

HEAD = """<!doctype html><html><head>
<meta name="description" content="Instant home-style Indian meals, ready in 6 minutes">
<meta property="og:title" content="The Foodify Company">
<meta property="og:image" content="/x.jpg">
<meta name="twitter:card" content="summary">
<link rel="stylesheet" href="/a.css"><script src="/a.js"></script>
<script type="application/ld+json">{"@type": "Product","name":"Express Dal Fry"}</script>
</head><body>"""
FOOT = f"<footer>&copy; {YEAR} The Foodify Company</footer></body></html>"
PAD  = "<p>" + ("Home-style Indian food, gently dried. " * 30) + "</p>"

LEAK    = "// 3. Inject JS step-switching script to show only one box at a time" if MODE == "bad" else ""
COUNTER = "<span>70 people are viewing this right now</span>" if MODE == "bad" else ""

PAGES = {
 "/":            HEAD + "<h1>A real meal in six minutes</h1>" + PAD + '<a href="/product/express-dal-fry/">Dal Fry</a>' + FOOT,
 "/product/express-dal-fry/": HEAD + "<h1>Express Dal Fry</h1>" + COUNTER + PAD + FOOT,
 "/shop/":       HEAD + "<h1>Shop</h1>" + PAD + '<a href="/?add-to-cart=42">Add</a>' + FOOT,
 "/cart/":       HEAD + "<h1>Cart</h1>" + PAD + "<p>1 item</p>" + FOOT,
 "/checkout/":   HEAD + "<h1>Checkout</h1>" + PAD + """
   <input name="billing_first_name"><input name="billing_phone">
   <input name="billing_email" required><input name="billing_postcode">
   <input name="billing_city"><select name="billing_state" id="billing_state"><option value="UP">Uttar Pradesh</option></select>
   <input name="billing_address_1"><input name="billing_address_2">
   <input name="shipping_first_name"><input name="shipping_postcode">
   <li class="payment_method_cod">Cash on delivery</li><li class="payment_method_razorpay">Razorpay</li>""" + FOOT,
 "/my-account/": HEAD + "<h1>My account</h1>" + LEAK + PAD + FOOT,
 "/robots.txt":  "User-agent: *\nSitemap: /sitemap_index.xml\n",
 "/sitemap_index.xml": '<?xml version="1.0"?><sitemapindex></sitemapindex>',
}

class H(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        path = self.path.split("?")[0]
        if path == "/wp-sitemap.xml":                      # retired by Rank Math
            self.send_error(404); return
        body = PAGES.get(path)
        if body is None: self.send_error(404); return
        b = body.encode()
        self.send_response(200)
        self.send_header("Content-Type", "text/plain" if path.endswith(".txt") else "text/html")
        self.send_header("Content-Length", str(len(b)))
        self.send_header("Set-Cookie", "woocommerce_items_in_cart=1; Path=/")
        self.end_headers(); self.wfile.write(b)
    def log_message(self, *a): pass

socketserver.TCPServer.allow_reuse_address = True
with socketserver.TCPServer(("127.0.0.1", int(sys.argv[1])), H) as s:
    s.serve_forever()
