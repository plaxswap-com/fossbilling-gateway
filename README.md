# Plaxswap - Bitcoin & Crypto Payments Gateway for FOSSBilling

Version 0.1.0 | FOSSBilling 0.8.9 or newer (0.8.x) | Apache-2.0

Your clients pay FOSSBilling invoices with Bitcoin, USDT, USDC, ETH, SOL, LTC and other coins on Plaxswap's hosted checkout. Confirmed payments go to your Plaxswap merchant balance. The invoice is marked paid once Plaxswap confirms the payment and this module has checked it against the Plaxswap API.

## Install

From the FOSSBilling extension directory (once listed): in the admin area open System > Payment Gateways > Install from the Extension Directory, find Plaxswap and install it.

By hand:

1. Make a folder named `Plaxswap` in `library/Payment/Adapter/` of your FOSSBilling installation.
2. Extract the release zip into that folder. You should end up with `library/Payment/Adapter/Plaxswap/Plaxswap.php` and `library/Payment/Adapter/Plaxswap/lib/`.
3. In the admin area open System > Payment Gateways and press New Payment Gateway. Plaxswap is listed under Install Locally; install it. It then appears in the Payment Gateways list, where you open it to configure it.

## Connect your Plaxswap account

1. Open a merchant account at https://plaxswap.com/merchants with Google or Telegram sign-in, finish the business profile and turn on your authenticator.
2. In the merchant dashboard open Developers. Under API keys, make a test key (`pxs_test_...`). Make a live key (`pxs_live_...`) when your test passes. Each key is shown once.
3. Open the Plaxswap gateway in System > Payment Gateways. The IPN Callback URL at the bottom of its page is your webhook URL. It looks like this (ID is the number at the end of the gateway page's address, /admin/invoice/gateway/ID):

   ```
   https://YOUR-FOSSBILLING/ipn.php?gateway_id=ID
   ```

   Use the same address as your FOSSBilling installation (the one in its config), with `https`.
4. Under Webhooks in the Plaxswap dashboard, paste that URL and choose Save, then choose Make a new secret to get the signing secret (`whsec_...`). Choose Send test to check the connection.
5. In FOSSBilling, on the Plaxswap gateway page, paste the test API key, the live API key (when you have it) and the webhook signing secret. Turn on Enabled and Allow One Time Payments, then press Update Gateway. The signing secret is a different value from the API key; saved keys show as Configured and stay hidden.
6. Make sure the gateway accepts the currencies of your invoices. Plaxswap prices payments in 67 fiat currencies, including USD, EUR, GBP and INR (the full list is in `lib/Decimal.php`).

## Test mode

FOSSBilling has an Enable Test Mode switch on every payment gateway. With it on, this module uses your test key. Payments made with a test key never move real coins: the Plaxswap checkout shows a test banner, a pretend address and buttons that act out each outcome (paid, partly paid, overpaid, late, expired, failed). The client sees "Test mode: no real coins move." above the pay button.

Anyone who can open an invoice can press those test buttons, so use test mode on a test installation or while no real clients are paying. Turn test mode off when you go live; the module then uses the live key and ignores test events.

A good test: open an unpaid invoice as a client, choose Plaxswap, and on the Plaxswap test checkout act out paid. The invoice turns paid and Invoices > Transactions shows a processed Plaxswap transaction. Then try partly paid and expired on new invoices: those invoices stay unpaid.

## How a payment works

- The client chooses Plaxswap on an invoice. The module asks Plaxswap for a payment of the invoice total in the invoice currency and FOSSBilling sends the client straight on to Plaxswap's checkout (a Pay with crypto button is shown if the browser does not follow). Opening the pay page again within 50 minutes reuses the same payment, so a client is not asked to pay twice for one invoice.
- The client picks a coin and network and sends the amount shown (QR code included).
- Plaxswap confirms the payment, adds it to your merchant balance and sends a signed webhook to `ipn.php`. The module checks the signature, then reads the payment back from the Plaxswap API and checks that it is this site's payment for this invoice, amount, currency and mode. Only then does it add the amount to the client's balance and apply it to the invoice, once.
- If the webhook is late, the payment is also checked when the client comes back from the checkout. Plaxswap only accepts https return links, so this return step (and the checkout's link back to the invoice) works when your FOSSBilling address uses https.
- These leave the invoice unpaid: partly paid, expired, cancelled and failed payments, payments held for a compliance review, coins that arrive after the payment expired (late), a payment for a different amount or currency than the invoice, and a second payment for an invoice that is already paid. Partly paid, late, held and mismatched payments are kept in Invoices > Transactions as errors (the Status column shows an error code; click it, or open the transaction, to read the explanation), so you can settle them by hand.
- A repeated webhook (Plaxswap retries deliveries for about a day) is recognised and ignored.
- If the Plaxswap API cannot be reached while a webhook is processed, the transaction is kept as an error. Open Invoices > Transactions and press Process Again on that transaction.

## Fees and your money

These are the fees as published on https://plaxswap.com/fees and https://plaxswap.com/developers/payments:

- Platform fee: 1.50% of each payment, paid by your client and shown at checkout. This module creates payments with `customerPaysFee: true`.
- At checkout your client also pays the conversion and network costs of the coin they choose, plus a 0.50% cushion for price moves while the coins confirm. Anything extra reaches you.
- Confirmed payments are added to your Plaxswap merchant balance, in the coin and network you receive. Plaxswap holds these coins until you withdraw them to your own wallet.
- Withdrawals: 0.30% of each withdrawal from your merchant balance, plus the network fee. Both are shown before you approve.

## Privacy

Sent to Plaxswap for each payment: the invoice total and currency, the invoice number, a reference made by this module, the FOSSBilling return and cancel links, and the invoice, gateway and site references described below. The client's name and email are not sent.

The module adds these metadata fields to each Plaxswap payment: `integration`, `fossbilling_invoice`, `fossbilling_gateway`, `fossbilling_site` (a short hash of your FOSSBilling address) and `fossbilling_verification` (an HMAC made with your API key). They let the module recognise its own payments and refuse any payment whose amount, currency or invoice was changed. No database table is added; the module uses FOSSBilling's own transactions and cache.

## For developers

- Code: `Plaxswap.php` (the adapter class `Payment_Adapter_Plaxswap`) and `lib/` (API client, webhook signature check, exact decimals).
- Tests: `php tests/run-tests.php` runs the adapter against stand-ins for the FOSSBilling services and a fake Plaxswap API. No Composer, network or database is needed.
- Local Plaxswap copy: with a test key, you can point the module at a Plaxswap copy on the same computer by adding `define('PLAXSWAP_FOSSBILLING_SANDBOX_URL', 'http://127.0.0.1:4201');` to FOSSBilling's `config.php`. Only `http://127.0.0.1:port` is accepted, and live keys always use https://plaxswap.com.
- API reference: https://plaxswap.com/developers/payments and https://plaxswap.com/api/v1/openapi.json

## Support

https://plaxswap.com/support | support@plaxswap.com

## License

Apache License 2.0. See `LICENSE`.
