# Fonts — WP-03 / WP-04

Sourced 25 Aug 2026 from the **upstream Google Fonts repository**, not the CSS
API. The API serves per-subset files under generated names, and with a default
curl UA it serves **TTF rather than woff2** — both traps worth knowing.

Subset to Latin + Latin-1 + Latin Ext-A + punctuation + currency (U+20A0–20BF,
which is where ₹ lives). Variable axes preserved. `SHA256SUMS` pins both.

| File | Axes | Size |
|---|---|---|
| `Fraunces-Variable.woff2` | `opsz 9..144`, `wght 100..900`, `SOFT 0..100`, `WONK 0..1` | 162 KB |
| `InstrumentSans-Variable.woff2` | `wdth 75..100`, `wght 400..700` | 82 KB |

Filenames match what `theme.json` already referenced. Total **245 KB of the
900 KB page budget**.

## Two decisions before WP-03 signs off

**1. Instrument Sans has no rupee glyph.** U+20B9 is absent from every cmap
subtable — it carries `$` and `€` but not `₹`. The UI font is specified for
"tabular numerals for all prices", so **every price on the store renders its ₹
from a fallback font**. It degrades to the platform sans (Roboto, SF, Segoe —
all have it), so it is legible rather than broken, but the symbol will not match
the digits beside it. Fraunces *does* have ₹.

Options: accept the platform fallback; serve U+20B9 from Fraunces via a
`unicode-range` face; or render prices in Fraunces. **Needs a look on a real
device before the type system is signed off.**

**2. Fraunces at 162 KB is 18% of the page budget.** Pinning `SOFT` and `WONK`
to chosen values drops it to **90 KB — a 45% saving** — at the cost of runtime
control over the two axes that make Fraunces "soft". If the design settles on
one SOFT value, pin it:

```bash
fonttools varLib.instancer Fraunces-Variable.woff2 SOFT=30 WONK=0 \
  --output Fraunces-Variable.woff2
```

## Deployment

- Preload **both** — they are the only two, and both are above the fold.
- `theme.json` declares them under `settings.typography.fontFamilies`; WordPress
  emits the `@font-face` rules. Nothing needs enqueueing in `functions.php`.
- WP-04 requires fonts self-hosted and preloaded. These are self-hosted; the
  preload hints are still to be added when templates land.
