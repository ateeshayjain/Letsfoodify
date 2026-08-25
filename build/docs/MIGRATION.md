# Host migration runbook

Current: Hostinger, PHP 8.2.30, their own hCDN edge.

> **Before you commit to this at all:** the measurement that prompted the move was taken in
> a browser that already had a WooCommerce cart cookie, which legitimately forces a cache
> bypass. Re-test clean first:
>
> ```bash
> curl -sI https://letsfoodify.com/ | grep -i 'hcdn-cache\|cache-status'
> ```
>
> A `HIT` means caching works and this migration is optional. Only a `BYPASS` from a clean,
> cookie-free request justifies moving.

## Sequence

1. **Provision** the new host. Match or exceed PHP 8.2, and confirm: Redis or Memcached
   object cache, WP-CLI over SSH, staging environments, daily backups, Woo-aware page rules.
2. **Migrate to the new host's staging first**, never straight to production. Full file
   copy plus database.
3. **Verify parity** before touching DNS — every one of these has bitten a migration:
   - `wp cron event list` — scheduled events intact
   - Transactional email actually delivers (this is the most common breakage; configure
     SMTP explicitly, do not trust `mail()`)
   - Razorpay webhook endpoint reachable and signature validation passing
   - SSL certificate issued and auto-renewing
   - File upload limits, `max_execution_time`, and memory limit
   - Object cache actually connected, not just installed
4. **Lower the DNS TTL to 300s at least 24 hours before** the switch. Do this early or the
   cutover window becomes a cutover day.
5. **Switch DNS** in the same low-traffic window as the site cutover if the schedule
   allows — two disruptions cost more than one.
6. **Run `smoke-test.sh` against the new host** before and after the DNS change.
7. **Keep the old host live and paid for 30 days.** It is the rollback.

## Gotchas specific to this store

- **Order data is the asset.** Export and verify the database before anything moves, and
  test the restore, not just the dump.
- **Razorpay settlement account** is tied to the merchant account, not the host — nothing
  to change, but confirm webhooks resolve to the new IP.
- **The hCDN edge disappears** with Hostinger. The new host needs its own CDN configured,
  or Cloudflare in front. Do not assume the new host is faster until measured.
- **Email from a new IP starts with no sending reputation.** Configure SPF, DKIM and DMARC
  on day one, and send transactional mail through a provider (SES, Postmark, Brevo) rather
  than the host's SMTP.
