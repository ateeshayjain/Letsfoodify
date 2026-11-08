# Lane D — retention features (Nov 2026)

WhatsApp order updates (9), repeat orders (12), the abandoned-cart reminder (13)
and referrals (14). No paid plugin, no third-party script, no new CSS/JS file.
**All four ship OFF**: each exists only once its options are complete, and a
half-configured feature shows an admin notice naming what is missing.

Tests: `php tests/retention-test.php` (213 assertions, pure halves of all four
files plus a run of the real `foodify_partner_coupons_on_order()`).

## What each one does, and the decisions behind it

### 9 · WhatsApp order updates — `inc/whatsapp.php`
- Meta's WhatsApp Cloud API, called directly. Credentials **only** in
  `wp-config.php` (`FOODIFY_WA_TOKEN`, `FOODIFY_WA_PHONE_ID`) — never an option,
  never the repo. On only when both exist AND `foodify_whatsapp_enabled = yes`
  AND language, Graph API version and at least one order template are set.
- Consent: an unticked "Send order updates on WhatsApp to this number" under the
  mobile field; stored per order (`_foodify_wa_optin`). Every order page carries
  "Stop WhatsApp updates" (guest pages are checked by order key).
- Status → message: `processing` = confirmed; `completed` = shipped (WP-10's
  completed email is "On its way"). Out-for-delivery / delivered are mapped only
  if a plugin registers those statuses — today none does.
- Tracking link: `inc/fulfilment.php` has none. A courier integration can supply
  one through the `foodify_order_tracking_url` filter; then the
  `order_shipped_tracking` template is used, else the plain one.
- A status change only enqueues an Action Scheduler job. The send re-checks
  consent, refund state and "already sent for this event". 429/5xx/network
  errors retry at 1 min, 5 min, 30 min, 2 h; other 4xx stop. Failures become an
  order note with event, HTTP status and Meta's numeric code only.
- **Cost:** Meta bills per template message at a per-country rate it sets and
  changes. No rate is quoted here because none could be verified. Look it up on
  Meta's "WhatsApp Business Platform pricing" page and in WhatsApp Manager →
  Billing; it is a running cost the client approves before switching on.

### 12 · Repeat orders — `inc/repeat-orders.php`
- "Repeat this order every 2 / 4 / 6 weeks" on the order-received page and on each
  order in My Account (signed-in owners only). Schedules live in user meta
  `_foodify_repeat_orders`. My Account → Repeat orders: pause / resume, skip next,
  change interval, cancel.
- N days before each date (`foodify_repeat_reminder_days`) ONE email — and a
  WhatsApp only if 9 is on, the source order opted in, and a `repeat_reminder`
  template is configured — with a signed link that adds the items (sold-out ones
  left out by name, short stock reduced and said) and opens checkout. Nothing
  is ever charged automatically.
- **Discount = an auto-applied coupon, not a cart fee.** A coupon is a record on
  the order and the GST invoice; single use is WooCommerce's own usage counter;
  it is restricted to the customer's email and the schedule's products. One
  coupon per cycle (unique code, usage limit 1, expires with the link,
  individual use), at most one repeat coupon per cart, and a skipped / paused /
  cancelled / missed cycle deletes its unused coupon. Unset option = no discount
  and the copy says "Repeat this order", never "save" or "subscribe".
- Signed links: HMAC-SHA256 with a per-purpose key derived from
  `wp_salt('auth')`, an expiry, and a nonce stored on the schedule.
  **Single use means one order, not one click**: mail scanners open links before
  people do, so opening only fills a cart; placing an order rotates the nonce and
  kills the link, as do skip/pause/cancel. A link lasts until the next cycle's
  reminder would go out; a cycle nobody ordered from then rolls forward.

### 13 · Abandoned-cart reminder — `inc/cart-recovery.php`
- Unticked "Email me my cart if I don't finish checking out" directly under
  email. Ticking it (with a valid email typed) is sent to the site as it happens
  through `?wc-ajax=foodify_cart_consent` (nonce + 20/10 min per IP); the server
  reads the cart from the session, never from the request. Unticking deletes the
  snapshot. Signed-in customers: the same choice in Your details, default off,
  kept in step with the checkout tick.
- Snapshot = email + product/variation/qty + timestamps, in a non-public CPT
  (`foodify_cart_snap`: public/UI/REST/query var/export all off). Not an option:
  personal data that grows with traffic does not belong in the options table.
- ONE reminder after `foodify_cart_reminder_delay_minutes`, re-decided at send
  time: any order with that email since the snapshot cancels it. Deleted on
  order, 7 days after sending, on untick, or via "Delete it now" in the email; a
  daily sweep catches anything missed. Wired into Tools → Erase Personal Data.
- Without JavaScript the tick only takes effect at Place order — i.e. never for
  an abandoned cart. Accepted: it fails towards not emailing.

### 14 · Referrals — `inc/referrals.php`
- Off unless all four options are set: `foodify_referral_give`, `_get`,
  `_min_order`, `_monthly_cap` (`0` = no cap, explicitly).
- A customer with ≥1 completed order gets a code (`REF` + 6 unambiguous
  characters, a real WooCommerce coupon) and a link `/cart/?ref=CODE` (cart is
  never page-cached). Friend: ₹give off a first order above the minimum — the code
  is saved in the session and applied once the cart reaches the minimum, or typed.
- Checks (pure `foodify_referral_friend_check`), at apply time and again before
  the order is created with the posted details: not the referrer's account,
  email (Gmail dots/+tags folded), mobile, or saved address; no previous order by
  that email OR mobile; that friend never referred before.
- Referrer: a single-use ₹get coupon (`RWD…`, email-restricted) only on the
  friend's order reaching `completed`; withdrawn (trashed) if that order is
  refunded below the minimum before the reward is used; capped per month;
  emailed without naming the friend.
- **Never partner sales.** Partner attribution counts a coupon only if it has
  `_foodify_partner_id`; referral and repeat coupons are created without it,
  tagged `_foodify_coupon_kind`, and the meta is stripped again if set in
  wp-admin. The test runs the real `foodify_partner_coupons_on_order()` on an
  order carrying a partner code, a referral code, a reward and a repeat coupon:
  only the partner code is returned and it is attributed the whole net.

## Suggested values — client to confirm (none are written anywhere)
| Option | Suggestion |
|---|---|
| `foodify_repeat_reminder_days` | 2 |
| `foodify_repeat_discount` | unset (no discount) until margin is checked |
| `foodify_cart_reminder_delay_minutes` | 60 |
| `foodify_referral_give` / `_get` | client's figures (e.g. equal amounts) |
| `foodify_referral_min_order` | above the typical first basket |
| `foodify_referral_monthly_cap` | a small number, e.g. 5 |
| `foodify_whatsapp_*` | after Meta setup and cost approval |

## Not verifiable here (staging checklist)
See the lane handoff; in short: the real Cloud API call and error shapes, Action
Scheduler actually running, WooCommerce's rendered checkbox markup, coupon
exception messages, guest nonces on wc-ajax, `wc_get_orders` by `billing_phone`
and `meta_query` under HPOS, emails through the WC mailer, and the rewrite
endpoints.
