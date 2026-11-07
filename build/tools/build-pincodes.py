#!/usr/bin/env python3
"""Build the theme's offline PIN-code table: theme/foodify/assets/data/pincodes.json.

WHY. Checkout filled city and state by sending the customer's PIN code to
api.postalpincode.in — an unofficial free service, called from the customer's
browser on the most important page of the shop (REVIEW-NOTES item 5). The theme
now answers from its own copy of India Post's directory instead, at
/wp-json/foodify/v1/pin/<pin>. Nothing leaves the site.

SOURCE. India Post, "All India Pincode Directory", published on data.gov.in
under the Government Open Data License – India (attribution required — it is
written into the output and into assets/data/SOURCE.md).

Accepts either form of that data:
  * the data.gov.in CSV (columns include pincode, Districtname/district,
    statename) — download the current file there before launch;
  * the JSON copy in the npm package india-pincode-lookup (2015 snapshot).

    python3 tools/build-pincodes.py <path-to-csv-or-json>

CORRECTIONS. Older snapshots predate two changes that matter at checkout,
because the STATE decides the GST split and the shipping zone:
  * Telangana (2014): its ten districts are still filed under Andhra Pradesh.
  * Ladakh (2019): Leh and Kargil are still filed under Jammu & Kashmir.
Both are corrected by district. A PIN filed under two states gives no state at
all — the customer picks it — rather than a guess.
"""
import csv, collections, datetime, json, os, re, sys

KIT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(KIT, "theme", "foodify", "assets", "data", "pincodes.json")

# Source state name -> WooCommerce state CODE (woocommerce/i18n/states.php, IN).
STATE = {
    "andaman & nicobar islands": "AN", "andaman and nicobar islands": "AN",
    "andhra pradesh": "AP", "arunachal pradesh": "AR", "assam": "AS", "bihar": "BR",
    "chandigarh": "CH", "chattisgarh": "CT", "chhattisgarh": "CT",
    "dadra & nagar haveli": "DN", "dadra and nagar haveli": "DN",
    "daman & diu": "DD", "daman and diu": "DD",
    "dadra and nagar haveli and daman and diu": "DN",
    "delhi": "DL", "goa": "GA", "gujarat": "GJ", "haryana": "HR", "himachal pradesh": "HP",
    "jammu & kashmir": "JK", "jammu and kashmir": "JK", "jharkhand": "JH",
    "karnataka": "KA", "kerala": "KL", "ladakh": "LA", "lakshadweep": "LD",
    "madhya pradesh": "MP", "maharashtra": "MH", "manipur": "MN", "meghalaya": "ML",
    "mizoram": "MZ", "nagaland": "NL", "odisha": "OR", "orissa": "OR",
    "pondicherry": "PY", "puducherry": "PY", "punjab": "PB", "rajasthan": "RJ",
    "sikkim": "SK", "tamil nadu": "TN", "telangana": "TS", "tripura": "TR",
    "uttar pradesh": "UP", "uttarakhand": "UK", "uttaranchal": "UK", "west bengal": "WB",
}
TELANGANA = {"adilabad", "hyderabad", "k.v.rangareddy", "rangareddy", "ranga reddy", "karim nagar",
             "karimnagar", "khammam", "mahabub nagar", "mahbubnagar", "medak", "nalgonda",
             "nizamabad", "warangal"}
LADAKH = {"leh", "kargil", "leh ladakh"}


def rows(path):
    if path.endswith(".json"):
        for r in json.load(open(path, encoding="utf-8")):
            yield str(r.get("pincode", "")), r.get("districtName", ""), r.get("stateName", "")
        return
    with open(path, encoding="utf-8-sig", newline="") as f:
        for r in csv.DictReader(f):
            k = {re.sub(r"[^a-z]", "", key.lower()): v for key, v in r.items() if key}
            yield (k.get("pincode", ""), k.get("districtname") or k.get("district", ""),
                   k.get("statename") or k.get("state", ""))


def norm(s):
    return re.sub(r"\s+", " ", (s or "").strip()).lower()


def main(src):
    states, districts = collections.defaultdict(collections.Counter), collections.defaultdict(collections.Counter)
    unknown = collections.Counter()
    for pin, district, state in rows(src):
        pin = re.sub(r"\D", "", str(pin))
        if len(pin) != 6:
            continue
        d, st = norm(district), norm(state)
        code = STATE.get(st)
        if code == "AP" and d in TELANGANA:
            code = "TS"
        if code == "JK" and d in LADAKH:
            code = "LA"
        if not code:
            unknown[state] += 1
            continue
        states[pin][code] += 1
        # "Bilaspur(CGH)" disambiguates districts for India Post; in a City
        # field the customer sees it, so the tag goes.
        district = re.sub(r"\s*\([^)]*\)\s*$", "", district or "").strip()
        if district:
            districts[pin][district.title() if district.isupper() else district] += 1

    dnames = sorted({d for c in districts.values() for d in c})
    codes = sorted({c for s in states.values() for c in s})
    di, si = {d: i for i, d in enumerate(dnames)}, {c: i for i, c in enumerate(codes)}
    pins = {}
    for pin in sorted(states):
        st = states[pin]
        s_idx = si[next(iter(st))] if len(st) == 1 else -1      # two states: say nothing
        d_idx = di[districts[pin].most_common(1)[0][0]] if districts[pin] else -1
        pins[pin] = [d_idx, s_idx]

    out = {
        "source": "India Post, All India Pincode Directory (data.gov.in), Government Open Data License - India",
        "input": os.path.basename(src),
        "built": datetime.date.today().isoformat(),
        "corrections": "Telangana districts moved from AP to TS; Leh and Kargil from JK to LA; PINs in two states carry no state",
        "states": codes, "districts": dnames, "pins": pins,
    }
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, "w", encoding="utf-8") as f:
        json.dump(out, f, ensure_ascii=False, separators=(",", ":"))
    amb = sum(1 for v in pins.values() if v[1] < 0)
    print(f"{len(pins)} PIN codes, {len(dnames)} districts, {len(codes)} states -> {OUT} "
          f"({os.path.getsize(OUT):,} bytes); {amb} with no single state")
    if unknown:
        # Loud, not silent: a state name this table does not know is a PIN that
        # would fill a city and leave the state wrong or blank.
        print("UNMAPPED state names (add them to STATE):", dict(unknown))
        sys.exit(1)


if __name__ == "__main__":
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    main(sys.argv[1])
