#!/usr/bin/env python3
"""theme/foodify/assets/data/sample-products.json — the SAMPLE products the setup
screen (inc/setup.php) can add to an EMPTY store, so the design can be seen on a
blank WordPress.

They are the prototype's fixture rows (tools/render-preview.py PRODUCTS and
PRODUCT_INFO), exported rather than retyped so the two cannot drift. They are
NOT the shop's catalogue: names are real SKUs, but prices, pack sizes and
ingredient lists are fixture values. The real catalogue is in the live shop's
database and moves with WooCommerce's own Products → Export / Import.

Ratings and review counts are deliberately NOT exported: a sample product may
not arrive carrying reviews nobody wrote.

    python3 tools/export-sample-products.py            # rewrite the file
    python3 tools/export-sample-products.py --check    # exit 1 if it is stale
"""
import ast, json, os, sys

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(HERE, "render-preview.py")
OUT = os.path.join(HERE, "..", "theme", "foodify", "assets", "data", "sample-products.json")

# The range a product is sold under decides how it is made (pa_prep) and its category.
RANGE = {
    "Express":     {"category": "express",       "category_name": "Express",     "prep": "hot-water"},
    "Hot & Fresh": {"category": "hot-and-fresh", "category_name": "Hot & Fresh", "prep": "cooking"},
    "Flavors":     {"category": "flavors",       "category_name": "Flavors",     "prep": "drinking-water"},
}


def fixtures():
    src = open(SRC, encoding="utf-8").read()
    ns = {}
    for node in ast.parse(src).body:
        if isinstance(node, ast.Assign) and getattr(node.targets[0], "id", "") in ("PRODUCTS", "PRODUCT_INFO"):
            exec(compile(ast.Module([node], []), SRC, "exec"), ns)
    return ns["PRODUCTS"], ns["PRODUCT_INFO"]


def build():
    products, info = fixtures()
    rows = []
    for name, rng, mins, price, was, _colour, _rating, _reviews, servings, flags in products:
        i, r = info[name], RANGE[rng]
        rows.append({
            "name": name,
            "slug": i["slug"],
            "category": r["category"],
            "category_name": r["category_name"],
            "prep": r["prep"],
            "prep_minutes": int(str(mins).split()[0]),
            # `was` is the struck price: when present the product is on sale at `price`.
            "regular_price": was or price,
            "sale_price": price if was else 0,
            "servings": servings,
            "net_quantity": i.get("net", ""),
            "cooked_weight": i.get("cooked", ""),
            "dietary": i.get("diet", []),
            "ingredients": i.get("ingredients", ""),
            "excerpt": i.get("excerpt", ""),
            "in_stock": "soldout" not in flags,
        })
    return json.dumps(rows, ensure_ascii=False, indent=1) + "\n"


if __name__ == "__main__":
    text = build()
    if "--check" in sys.argv:
        ok = os.path.exists(OUT) and open(OUT, encoding="utf-8").read() == text
        print("sample-products.json is current" if ok else "sample-products.json is STALE — run tools/export-sample-products.py")
        sys.exit(0 if ok else 1)
    open(OUT, "w", encoding="utf-8").write(text)
    print("wrote", os.path.relpath(OUT), "·", len(json.loads(text)), "products")
