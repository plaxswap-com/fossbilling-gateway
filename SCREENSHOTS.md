# Screenshots

Taken on 18 November 2026 from a real FOSSBilling 0.8.9 install on this Mac, with this module installed from `dist/plaxswap-fossbilling-0.1.0.zip` and a local Plaxswap test copy (test key, no real coins). Browser viewport 1280 x 860, saved by the built-in browser at 800 px wide. Files are in `screenshots/`.

1. `1-gateway-settings-top.jpg`: admin, System > Payment Gateways > Plaxswap (crypto): the setup text and Gateway Basics.
2. `1-gateway-settings.jpg`: the same page lower down: live key, test key (Configured), webhook signing secret (Configured), Enabled, Allow One Time Payments, Enable Test Mode, and the IPN Callback URL. Saved keys are never shown.
3. `2-invoice-pay-button.jpg`: client area, the payment step for an unpaid 49.50 USD invoice with "Test mode: no real coins move." and the Pay with crypto button. FOSSBilling normally forwards the client to the checkout at once, so this page was rendered from the real page's HTML with only the forwarding script left out.
4. `3-plaxswap-checkout.jpg`: the Plaxswap test checkout for that invoice (test banner, 49.5 USD, 1.5% fee paid by the customer).
5. `4-invoice-paid.jpg`: client area, invoice 1 marked Paid after acting out "paid".
6. `5-transactions.jpg`: admin, Invoices > Transactions: three processed payments (paid, paid on return before the webhook, overpaid), the underpaid and late payments kept as errors (code 5100), and a webhook with an old signature refused (code 5002).
7. `6-underpaid-error.jpg`: admin transaction detail for a partly paid payment: the "Underpaid" explanation, status Error, invoice not paid.

Still to take for a listing page, if wanted: a sharper 1280 px set (these are 800 px), and the Plaxswap dashboard webhook delivery list.

The directory itself shows only an icon (`icon.png` at the package root, 256 x 256, made from the Plaxswap app icon).
