# Changelog

## 0.1.0 (18 November 2026)

First release.

- Pay FOSSBilling invoices on Plaxswap's hosted checkout, priced in the invoice currency (67 fiat currencies).
- Live and test API keys, chosen by FOSSBilling's Test mode switch, and one webhook signing secret. All three are stored as secret fields.
- Signed webhooks to `ipn.php?gateway_id=ID`, checked as Plaxswap documents (HMAC-SHA256 of "t.body", 5 minute window, several v1 values allowed).
- The payment is read back from the Plaxswap API before anything is applied. An invoice is marked paid only for a final, credited, on-time payment of the right amount and currency, made by this site's gateway for this invoice, and only once.
- Partly paid, late, held for review and mismatched payments are kept as error transactions with a plain explanation. Expired, cancelled and failed payments leave the invoice unpaid and are logged.
- Repeated webhooks are ignored. The client's return from the checkout also checks the payment, in case the webhook is late.
- The pay page reuses an open payment for 50 minutes instead of making a new one.
- Tested end to end on FOSSBilling 0.8.9 with a local Plaxswap test copy: paid, partly paid, expired, failed, overpaid, late, return before the webhook, repeated and resent webhooks, test ping.
- Fixes from that test: a partly paid transaction no longer shows the full invoice amount; the pay step shows amounts as prices (49.50 USD); the package files carry the build date, so PHP OPcache picks up an upgraded version.
