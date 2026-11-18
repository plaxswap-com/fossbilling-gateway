<?php

declare(strict_types=1);
/**
 * Plaxswap crypto payments for FOSSBilling.
 *
 * Clients pay FOSSBilling invoices with Bitcoin, USDT, USDC, ETH, SOL, LTC and other coins on Plaxswap's hosted
 * checkout. Confirmed payments go to the merchant's Plaxswap balance. The invoice is marked paid only after
 * Plaxswap's own API (never the browser, never the webhook body alone) reports the payment paid, for this invoice,
 * this amount and this currency, and only once.
 *
 * Install: put this folder at library/Payment/Adapter/Plaxswap/ (this file is Plaxswap.php inside it).
 *
 * @see https://plaxswap.com/developers/payments
 *
 * @license Apache-2.0
 * SPDX-License-Identifier: Apache-2.0
 */

require_once __DIR__ . '/lib/Decimal.php';
require_once __DIR__ . '/lib/Signature.php';
require_once __DIR__ . '/lib/Api.php';

use Plaxswap\FOSSBilling\Api;
use Plaxswap\FOSSBilling\ApiException;
use Plaxswap\FOSSBilling\Decimal;
use Plaxswap\FOSSBilling\Signature;

class Payment_Adapter_Plaxswap implements FOSSBilling\InjectionAwareInterface
{
    public const VERSION = '0.1.0';

    /** A checkout is reused for this long; Plaxswap payments made here stay open for 60 minutes. */
    private const REUSE_SECONDS = 3000;
    private const EXPIRES_IN_MINUTES = 60;
    private const REF_PATTERN = '/^FB-([a-f0-9]{8})-([0-9]{1,10})-[a-f0-9]{8}$/D';
    private const STATUSES = ['pending', 'confirming', 'paid', 'partially_paid', 'expired', 'cancelled', 'failed'];

    protected ?Pimple\Container $di = null;

    private ?Api $api = null;

    public function __construct(private $config)
    {
        $live = !$this->isTestMode();
        $keyField = $live ? 'api_key' : 'test_api_key';
        $key = trim((string) ($this->config[$keyField] ?? ''));
        if ($key === '') {
            throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'Plaxswap', ':missing' => $live ? 'Live API key' : 'Test API key'], 4001);
        }
        if (!preg_match(Api::KEY_PATTERN, $key, $match) || ($match[1] === 'live') !== $live) {
            throw new Payment_Exception($live
                ? 'The Plaxswap live API key must start with pxs_live_. Turn on test mode to use a pxs_test_ key.'
                : 'The Plaxswap test API key must start with pxs_test_.', [], 4002);
        }
        $secret = trim((string) ($this->config['webhook_secret'] ?? ''));
        if ($secret === '') {
            throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'Plaxswap', ':missing' => 'Webhook signing secret'], 4001);
        }
        if (!preg_match(Api::SECRET_PATTERN, $secret)) {
            throw new Payment_Exception('The Plaxswap webhook signing secret must start with whsec_. It is a different value from the API key.', [], 4002);
        }
    }

    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Pimple\Container
    {
        return $this->di;
    }

    public static function getConfig(): array
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions' => false,
            'description' => 'Clients pay invoices with Bitcoin, USDT, USDC, ETH, SOL, LTC and other coins on Plaxswap\'s hosted checkout. '
                . 'Payments go to your Plaxswap merchant balance. In your Plaxswap merchant dashboard, open Developers: create an API key, '
                . 'set the webhook URL to the IPN Callback URL shown at the bottom of this page (https://YOUR-FOSSBILLING/ipn.php?gateway_id=ID), '
                . 'then make a webhook signing secret. Enable Test Mode to use the test key; no real coins move in test mode.',
            'logo' => [
                'logo' => 'Plaxswap/plaxswap.png',
                'height' => '50px',
                'width' => '50px',
            ],
            'form' => [
                'api_key' => [
                    'password', [
                        'label' => 'Live API key (pxs_live_...)',
                        'required_when' => ['enabled' => true, 'test_mode' => false],
                        'secret' => true,
                    ],
                ],
                'test_api_key' => [
                    'password', [
                        'label' => 'Test API key (pxs_test_...)',
                        'required_when' => ['enabled' => true, 'test_mode' => true],
                        'secret' => true,
                    ],
                ],
                'webhook_secret' => [
                    'password', [
                        'label' => 'Webhook signing secret (whsec_...)',
                        'required_when' => ['enabled' => true],
                        'secret' => true,
                    ],
                ],
            ],
        ];
    }

    /**
     * The invoice's "Pay" step: makes (or reuses) a Plaxswap payment for the invoice total and currency, and shows a
     * button to its hosted checkout. Plaxswap has no recurring billing, so a subscription invoice is paid once.
     */
    public function getHtml($api_admin, int $invoice_id, bool $subscription): string
    {
        $invoice = $this->di['db']->load('Invoice', $invoice_id);
        if (!$invoice instanceof Model_Invoice) {
            return $this->message('This invoice could not be found.');
        }
        if ($invoice->status === Model_Invoice::STATUS_PAID) {
            return $this->message('This invoice is already paid.');
        }
        $currency = strtoupper((string) $invoice->currency);
        if (!Decimal::currencySupported($currency)) {
            return $this->message('Crypto payments are not available for invoices in :currency.', [':currency' => $currency]);
        }
        $invoiceService = $this->di['mod_service']('Invoice');
        $amount = Decimal::fromInvoiceTotal((float) $invoiceService->getTotalWithTax($invoice), $currency);
        if (!Decimal::positive($amount)) {
            return $this->message('This invoice has nothing to pay.');
        }

        try {
            $checkout = $this->checkout($invoice, $amount, $currency);
        } catch (ApiException|Payment_Exception $e) {
            $this->log('warning', 'Plaxswap: could not start a checkout for invoice #%s: %s', (string) $invoice->id, $e instanceof ApiException ? $e->apiCode : $e->getMessage());

            return $this->message('Crypto checkout is unavailable right now. Please try again in a few minutes.');
        }
        if (isset($checkout['notice'])) {
            return $this->message($checkout['notice']);
        }

        return $this->button($checkout['url'], $amount, $currency);
    }

    /**
     * Called by FOSSBilling for each callback to ipn.php?gateway_id=ID: Plaxswap's signed webhook (JSON body with an
     * x-px-signature header), or the client's browser coming back from the checkout (?plaxswap_ref=...).
     */
    public function processTransaction($api_admin, int $id, array $data, int $gateway_id): void
    {
        $tx = $this->di['db']->getExistingModelById('Transaction', $id);
        $server = is_array($data['server'] ?? null) ? $data['server'] : [];
        $get = is_array($data['get'] ?? null) ? $data['get'] : [];
        $raw = (string) ($data['http_raw_post_data'] ?? '');
        $signature = (string) ($server['HTTP_X_PX_SIGNATURE'] ?? '');

        try {
            if ($signature !== '' || str_starts_with(ltrim($raw), '{')) {
                $this->handleWebhook($tx, $raw, $signature, $gateway_id);
            } elseif (isset($get['plaxswap_ref']) && is_string($get['plaxswap_ref'])) {
                $this->handleReturn($tx, $get['plaxswap_ref'], $gateway_id);
            } else {
                $this->log('info', 'Plaxswap: ignored callback #%s (not a signed webhook or a checkout return)', (string) $id);
                $this->di['db']->trash($tx);
            }
        } catch (ApiException $e) {
            // Plaxswap busy or unreachable. The transaction is kept as an error; "Process" in the admin area retries it.
            throw new Payment_Exception('Plaxswap API unavailable (:code). Process this transaction again later.', [':code' => $e->apiCode], 5001);
        }
    }

    private function handleWebhook(Model_Transaction $tx, string $raw, string $signature, int $gatewayId): void
    {
        // Freshness is judged at the time FOSSBilling received the request, so an admin can process a stored
        // webhook again later; a replayed request gets a new receipt time and fails the 5 minute window.
        if (!Signature::verify($raw, $signature, [trim((string) $this->config['webhook_secret'])], $this->receivedAt($tx))) {
            throw new Payment_Exception('Plaxswap webhook signature is missing, invalid or too old', [], 5002);
        }

        try {
            $event = Signature::decodeEvent($raw);
        } catch (UnexpectedValueException) {
            throw new Payment_Exception('Plaxswap webhook body is not a valid event', [], 5003);
        }
        $tx->note = 'Plaxswap ' . $event['event'] . ' ' . $event['id'];
        $tx->txn_status = $event['event'];

        if ($event['event'] === 'ping') {
            $this->log('info', 'Plaxswap: test webhook received from merchant %s', $event['data']['merchantId']);
            $this->di['db']->trash($tx);

            return;
        }
        if ($event['livemode'] !== $this->isLive()) {
            $this->log('info', 'Plaxswap: ignored %s event %s (gateway is in %s mode)', $event['livemode'] ? 'live' : 'test', $event['id'], $this->isLive() ? 'live' : 'test');
            $this->di['db']->trash($tx);

            return;
        }
        $repeat = $this->di['db']->findOne(
            'Transaction',
            'gateway_id = :gateway_id AND id != :id AND note = :note AND status IN (:s1, :s2)',
            [':gateway_id' => $gatewayId, ':id' => $tx->id, ':note' => $tx->note, ':s1' => Model_Transaction::STATUS_PROCESSING, ':s2' => Model_Transaction::STATUS_PROCESSED]
        );
        if ($repeat) {
            $this->log('info', 'Plaxswap: ignored repeated delivery of event %s', $event['id']);
            $this->di['db']->trash($tx);

            return;
        }

        // The webhook body is only a hint: read the payment back from Plaxswap and decide from that.
        $payment = $this->api()->getPayment($event['data']['id']);
        $this->settle($tx, $payment, $gatewayId, true);
    }

    /** The client came back from the checkout: if the webhook has not arrived yet, ask Plaxswap directly. */
    private function handleReturn(Model_Transaction $tx, string $ref, int $gatewayId): void
    {
        $tx->note = 'Plaxswap checkout return';
        if (!preg_match(self::REF_PATTERN, $ref, $m) || $m[1] !== $this->siteTag() || ($tx->invoice_id && (int) $tx->invoice_id !== (int) $m[2])) {
            $this->log('info', 'Plaxswap: ignored a checkout return with an unknown reference');
            $this->di['db']->trash($tx);

            return;
        }
        $payments = $this->api()->findPaymentsByOrderId($ref);
        if (!$payments) {
            $this->di['db']->trash($tx);

            return;
        }
        $this->settle($tx, $payments[0], $gatewayId, false);
    }

    /** Applies a payment as Plaxswap's API reports it: marks the invoice paid once, or records why not. */
    private function settle(Model_Transaction $tx, array $payment, int $gatewayId, bool $fromWebhook): void
    {
        $claim = $this->claimOf($payment, $gatewayId);
        if ($claim === null) {
            // Made by another shop on the same Plaxswap merchant account: nothing to do here.
            $this->log('info', 'Plaxswap: payment %s was not made by this FOSSBilling gateway; ignored', (string) ($payment['id'] ?? ''));
            $this->di['db']->trash($tx);

            return;
        }
        if ($tx->invoice_id && (int) $tx->invoice_id !== $claim['invoice']) {
            throw new Payment_Exception('Plaxswap payment :id belongs to another invoice', [':id' => $payment['id']], 5004);
        }

        $tx->txn_id = $payment['id'];
        $tx->txn_status = $payment['status'];
        $tx->invoice_id = $claim['invoice'];
        // Only a paid payment carries the invoice amount. A partly paid one would otherwise show the full price in
        // the admin transaction list (found in the FOSSBilling 0.8.9 end-to-end test).
        $tx->amount = $payment['status'] === 'paid' ? $claim['amount'] : null;
        $tx->currency = $payment['status'] === 'paid' ? $claim['currency'] : null;
        $tx->type = 'payment';

        $this->withLock($payment['id'], $gatewayId, function () use ($tx, $payment, $claim, $fromWebhook): void {
            $this->settleUnderLock($tx, $payment, $claim, $fromWebhook);
        });
    }

    private function settleUnderLock(Model_Transaction $tx, array $payment, array $claim, bool $fromWebhook): void
    {
        $status = $payment['status'];
        $invoiceId = $claim['invoice'];

        if (in_array($status, ['pending', 'confirming'], true)) {
            if ($fromWebhook) {
                $this->log('info', 'Plaxswap: payment %s for invoice #%s is %s', $payment['id'], (string) $invoiceId, $status);
            }
            $this->di['db']->trash($tx);

            return;
        }
        if (in_array($status, ['expired', 'cancelled', 'failed'], true)) {
            $this->log('info', 'Plaxswap: payment %s for invoice #%s is %s; the invoice stays unpaid', $payment['id'], (string) $invoiceId, $status);
            $this->di['db']->trash($tx);

            return;
        }
        if ($status === 'partially_paid') {
            $this->problem($tx, sprintf('Underpaid: Plaxswap reports payment %s as partly paid (%s of the expected %s, in %s). Invoice #%d was not marked paid. Check the payment in your Plaxswap merchant dashboard and settle the rest by hand.',
                $payment['id'], $this->text($payment['receivedAmount'] ?? '0'), $this->text($payment['expectedAmount'] ?? '?'), $this->methodLabel($payment), $invoiceId));

            return;
        }

        // Paid: only a final, credited, on-time payment marks the invoice paid by itself.
        if (($payment['underReview'] ?? false) !== false || !Signature::validDate($payment['paidAt'] ?? null)) {
            $this->problem($tx, sprintf('Plaxswap payment %s is held for a compliance review. Invoice #%d was not marked paid yet; Plaxswap sends another webhook when the review ends.', $payment['id'], $invoiceId));

            return;
        }
        if (!$this->credited($payment)) {
            $this->problem($tx, sprintf('Plaxswap payment %s is marked paid but shows no amount received. Invoice #%d was not marked paid.', $payment['id'], $invoiceId));

            return;
        }
        if (($payment['late'] ?? false) === true) {
            $this->problem($tx, sprintf('Late payment: the coins for Plaxswap payment %s arrived after it expired. Plaxswap passed them to your balance. Invoice #%d was not marked paid by itself; check the amount and mark it paid by hand.', $payment['id'], $invoiceId));

            return;
        }

        $done = $this->otherTransaction($tx, $payment['id'], [Model_Transaction::STATUS_PROCESSING, Model_Transaction::STATUS_PROCESSED]);
        if ($done) {
            $this->log('info', 'Plaxswap: payment %s was already applied (transaction #%s)', $payment['id'], (string) $done->id);
            $this->di['db']->trash($tx);

            return;
        }
        // Saved while the lock is held, so a webhook and a checkout return for one payment cannot both apply it.
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);

        $invoice = $this->di['db']->findOne('Invoice', 'id = :id', [':id' => $invoiceId]);
        if (!$invoice instanceof Model_Invoice) {
            $this->problem($tx, sprintf('Plaxswap payment %s is for invoice #%d, which no longer exists.', $payment['id'], $invoiceId));

            return;
        }
        if ($invoice->status === Model_Invoice::STATUS_PAID) {
            $this->problem($tx, sprintf('Invoice #%d was already paid. Plaxswap payment %s (%s %s) was not applied a second time; refund the client or add it to their balance by hand.', $invoiceId, $payment['id'], $claim['amount'], $claim['currency']));

            return;
        }
        if (strcasecmp((string) $invoice->currency, $claim['currency']) !== 0) {
            $this->problem($tx, sprintf('Wrong currency: Plaxswap payment %s is in %s but invoice #%d is in %s. The invoice was not marked paid.', $payment['id'], $claim['currency'], $invoiceId, (string) $invoice->currency));

            return;
        }
        $invoiceService = $this->di['mod_service']('Invoice');
        $expected = (float) $invoiceService->getTotalWithTax($invoice);

        try {
            $invoiceService->validatePaymentAmount((float) $claim['amount'], $expected);
        } catch (Exception $e) {
            $this->problem($tx, sprintf('Wrong amount: Plaxswap payment %s is for %s %s but invoice #%d now totals %s. The invoice was not marked paid. %s', $payment['id'], $claim['amount'], $claim['currency'], $invoiceId, Decimal::fromInvoiceTotal($expected, $claim['currency']), $e->getMessage()));

            return;
        }

        $transactionService = $this->di['mod_service']('Invoice', 'Transaction');
        if (!$transactionService->claimForProcessing((int) $tx->id)) {
            return; // another worker holds this transaction
        }
        $tx->status = Model_Transaction::STATUS_PROCESSING;

        $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id);
        $description = 'Plaxswap payment ' . $payment['id'];
        $this->di['mod_service']('client')->addFunds($client, (float) $claim['amount'], $description, [
            'amount' => (float) $claim['amount'],
            'description' => $description,
            'type' => 'transaction',
            'rel_id' => $tx->id,
        ]);
        if ($invoiceService->isInvoiceTypeDeposit($invoice)) {
            $invoiceService->markAsPaid($invoice);
        } else {
            if (!$invoice->approved) {
                $invoiceService->approveInvoice($invoice, ['use_credits' => false]);
            }
            $invoiceService->payInvoiceWithCredits($invoice);
        }

        $tx->status = Model_Transaction::STATUS_PROCESSED;
        $tx->error = '';
        $tx->error_code = null;
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);
        $this->log('info', 'Plaxswap: payment %s paid invoice #%s (%s %s%s)', $payment['id'], (string) $invoiceId, $claim['amount'], $claim['currency'], $this->isLive() ? '' : ', test mode');
    }

    /**
     * What the payment says about itself, checked against the reference this gateway signed when it made the payment.
     * Null when the payment was not made by this FOSSBilling gateway.
     */
    private function claimOf(array $payment, int $gatewayId): ?array
    {
        $metadata = is_array($payment['metadata'] ?? null) ? $payment['metadata'] : [];
        if (($metadata['integration'] ?? null) !== 'fossbilling'
            || ($metadata['fossbilling_site'] ?? null) !== $this->siteTag()
            || ($metadata['fossbilling_gateway'] ?? null) !== (string) $gatewayId) {
            return null;
        }

        $id = $payment['id'] ?? null;
        $ref = $payment['orderId'] ?? null;
        $invoice = $metadata['fossbilling_invoice'] ?? null;
        $currency = $payment['currency'] ?? null;
        $checks = [
            'id' => is_string($id) && preg_match(Api::PAYMENT_ID_PATTERN, $id),
            'mode' => ($payment['livemode'] ?? null) === $this->isLive(),
            'status' => in_array($payment['status'] ?? null, self::STATUSES, true),
            'receive mode' => ($payment['receiveMode'] ?? null) === 'original',
            'invoice' => is_string($invoice) && ctype_digit($invoice),
            'reference' => is_string($ref) && preg_match(self::REF_PATTERN, $ref, $m) && $m[1] === $this->siteTag() && $m[2] === $invoice,
            'currency' => is_string($currency) && Decimal::currencySupported($currency),
            'amount' => Decimal::positive($payment['amount'] ?? null),
        ];
        foreach ($checks as $what => $ok) {
            if (!$ok) {
                throw new Payment_Exception('Plaxswap payment :id failed the :what check and was not applied', [':id' => is_string($id) ? $id : '?', ':what' => $what], 5005);
            }
        }
        $amount = Decimal::normalize($payment['amount']);
        $expected = $this->verification((int) $invoice, $gatewayId, $amount, $currency, $ref);
        if (!is_string($metadata['fossbilling_verification'] ?? null) || !hash_equals($expected, $metadata['fossbilling_verification'])) {
            throw new Payment_Exception('Plaxswap payment :id does not match the amount, currency or invoice it was made for, and was not applied', [':id' => $id], 5006);
        }

        return ['invoice' => (int) $invoice, 'amount' => $amount, 'currency' => $currency, 'ref' => $ref];
    }

    /** The open checkout for this invoice amount from the last 50 minutes, or a new Plaxswap payment. */
    private function checkout(Model_Invoice $invoice, string $amount, string $currency): array
    {
        $api = $this->api();
        $gatewayId = (int) ($this->config['gateway_id'] ?? 0);
        $cacheKey = 'plaxswap_checkout_' . hash('sha256', implode('|', [$this->siteTag(), $gatewayId, $invoice->id, $amount, $currency, $this->isLive() ? 'live' : 'test']));
        $cached = $this->cacheGet($cacheKey);
        if (is_array($cached) && isset($cached['id'], $cached['url']) && ($cached['created'] ?? 0) > time() - self::REUSE_SECONDS) {
            try {
                $payment = $api->getPayment($cached['id']);
                $status = $payment['status'] ?? '';
                if ($status === 'pending') {
                    return ['url' => $cached['url']];
                }
                if (in_array($status, ['confirming', 'paid'], true)) {
                    return ['notice' => 'Your payment for this invoice has arrived and is being confirmed. The invoice will show as paid shortly.'];
                }
                if ($status === 'partially_paid') {
                    return ['notice' => 'Part of your payment for this invoice has arrived. Please contact us to settle the rest.'];
                }
            } catch (ApiException $e) {
                if ($e->apiCode === 'connection_unavailable') {
                    return ['url' => $cached['url']];
                }
            }
        }

        $ref = 'FB-' . $this->siteTag() . '-' . (int) $invoice->id . '-' . bin2hex(random_bytes(4));
        $title = trim((string) ($invoice->serie ?? '') . (string) ($invoice->nr ?? ''));
        $body = [
            'amount' => $amount,
            'currency' => $currency,
            'receiveMode' => 'original',
            'orderId' => $ref,
            'description' => 'Invoice ' . ($title !== '' ? $title : '#' . (int) $invoice->id),
            'expiresInMinutes' => self::EXPIRES_IN_MINUTES,
            'customerPaysFee' => true,
            'metadata' => [
                'integration' => 'fossbilling',
                'fossbilling_invoice' => (string) (int) $invoice->id,
                'fossbilling_gateway' => (string) $gatewayId,
                'fossbilling_site' => $this->siteTag(),
                'fossbilling_verification' => $this->verification((int) $invoice->id, $gatewayId, $amount, $currency, $ref),
            ],
        ];
        $return = $this->httpsUrl($this->config['redirect_url'] ?? null);
        if ($return !== null) {
            $body['returnUrl'] = $return . (str_contains($return, '?') ? '&' : '?') . 'plaxswap_ref=' . rawurlencode($ref);
        }
        $cancel = $this->httpsUrl($this->config['cancel_url'] ?? null);
        if ($cancel !== null) {
            $body['cancelUrl'] = $cancel;
        }

        $payment = $api->createPayment($body, $ref);
        $claim = $this->claimOf($payment, $gatewayId);
        if ($claim === null || $claim['ref'] !== $ref || $claim['amount'] !== $amount || $claim['currency'] !== $currency || ($payment['status'] ?? '') !== 'pending') {
            throw new ApiException('unexpected_payment');
        }
        $url = $this->checkoutUrlOf($payment, $api);
        $this->cacheSet($cacheKey, ['id' => $payment['id'], 'url' => $url, 'created' => time()]);
        $this->log('info', 'Plaxswap: payment %s created for invoice #%s (%s %s)', $payment['id'], (string) $invoice->id, $amount, $currency);

        return ['url' => $url];
    }

    /** Plaxswap's own checkout link for the payment, accepted only on Plaxswap's origin. */
    private function checkoutUrlOf(array $payment, Api $api): string
    {
        $origin = $api->getOrigin() . '/';
        $url = $payment['checkoutUrl'] ?? null;
        if (is_string($url) && str_starts_with($url, $origin) && !preg_match('/[\s"\'<>]/', $url)) {
            return $url;
        }

        return $origin . 'checkout/' . $payment['id'];
    }

    private function verification(int $invoiceId, int $gatewayId, string $amount, string $currency, string $ref): string
    {
        $key = hash_hmac('sha256', 'plaxswap-fossbilling-v1', $this->apiKey());

        return hash_hmac('sha256', implode('|', ['v1', $this->siteTag(), $gatewayId, $invoiceId, $amount, $currency, $ref]), $key);
    }

    /** Short tag of this FOSSBilling installation, so two shops on one Plaxswap account never apply each other's payments. */
    private function siteTag(): string
    {
        $notify = (string) ($this->config['notify_url'] ?? '');
        $base = strtolower((string) strtok($notify, '?'));

        return substr(hash('sha256', 'plaxswap-site|' . $base), 0, 8);
    }

    private function credited(array $payment): bool
    {
        if (Decimal::positive($payment['receivedAmount'] ?? null)) {
            return true;
        }
        foreach (is_array($payment['receivedBalances'] ?? null) ? $payment['receivedBalances'] : [] as $balance) {
            if (is_array($balance) && Decimal::positive($balance['amount'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** Keeps the transaction as an error with a plain explanation for the admin, once per payment and reason. */
    private function problem(Model_Transaction $tx, string $message): void
    {
        $same = $this->di['db']->findOne(
            'Transaction',
            'txn_id = :txn_id AND gateway_id = :gateway_id AND id != :id AND status = :status AND error = :error',
            [':txn_id' => $tx->txn_id, ':gateway_id' => $tx->gateway_id, ':id' => $tx->id, ':status' => Model_Transaction::STATUS_ERROR, ':error' => $message]
        );
        if ($same) {
            $this->di['db']->trash($tx);

            return;
        }
        $tx->status = Model_Transaction::STATUS_ERROR;
        $tx->error = $message;
        $tx->error_code = 5100;
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);
        $this->log('warning', 'Plaxswap: %s', $message);
    }

    private function otherTransaction(Model_Transaction $tx, string $paymentId, array $statuses): ?object
    {
        $found = $this->di['db']->findOne(
            'Transaction',
            'txn_id = :txn_id AND gateway_id = :gateway_id AND id != :id AND status IN (:s1, :s2)',
            [':txn_id' => $paymentId, ':gateway_id' => $tx->gateway_id, ':id' => $tx->id, ':s1' => $statuses[0], ':s2' => $statuses[1] ?? $statuses[0]]
        );

        return is_object($found) ? $found : null;
    }

    /** One payment is applied by one request at a time (MySQL named lock, as FOSSBilling's Stripe adapter does). */
    private function withLock(string $paymentId, int $gatewayId, callable $callback): void
    {
        if (!isset($this->di['dbal'])) {
            $callback();

            return;
        }
        $name = 'fb:plaxswap:' . substr(hash('sha256', $gatewayId . ':' . $paymentId), 0, 48);
        if ((int) $this->di['dbal']->fetchOne('SELECT GET_LOCK(:lock_name, 10)', ['lock_name' => $name]) !== 1) {
            throw new Payment_Exception('Timed out waiting to process Plaxswap payment :id', [':id' => $paymentId], 5007);
        }

        try {
            $callback();
        } finally {
            $this->di['dbal']->fetchOne('SELECT RELEASE_LOCK(:lock_name)', ['lock_name' => $name]);
        }
    }

    private function api(): Api
    {
        if ($this->api === null) {
            $http = isset($this->di['http_client']) ? $this->di['http_client'] : Symfony\Component\HttpClient\HttpClient::create();
            $this->api = new Api($this->apiKey(), $http, 'Plaxswap-FOSSBilling/' . self::VERSION);
        }

        return $this->api;
    }

    private function apiKey(): string
    {
        return trim((string) ($this->config[$this->isLive() ? 'api_key' : 'test_api_key'] ?? ''));
    }

    private function isTestMode(): bool
    {
        return filter_var($this->config['test_mode'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function isLive(): bool
    {
        return !$this->isTestMode();
    }

    private function receivedAt(Model_Transaction $tx): int
    {
        $at = is_string($tx->created_at ?? null) ? strtotime($tx->created_at) : false;

        return $at !== false ? $at : time();
    }

    private function httpsUrl($url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') && strlen($url) <= 1500 ? $url : null;
    }

    private function methodLabel(array $payment): string
    {
        $receive = $payment['receive'] ?? null;
        $label = is_array($receive) ? ($receive['id'] ?? null) : (is_string($receive) ? $receive : null);

        return is_string($label) && preg_match('/^[a-z0-9-]{1,40}$/D', $label) ? $label : 'the receive coin';
    }

    private function text($value): string
    {
        return is_string($value) && preg_match('/^[0-9]{1,40}(?:\.[0-9]{1,30})?$/D', $value) ? $value : '?';
    }

    private function cacheGet(string $key): mixed
    {
        if (!isset($this->di['cache'])) {
            return null;
        }

        try {
            $item = $this->di['cache']->getItem($key);

            return $item->isHit() ? $item->get() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function cacheSet(string $key, array $value): void
    {
        if (!isset($this->di['cache'])) {
            return;
        }

        try {
            $item = $this->di['cache']->getItem($key);
            $item->set($value);
            $item->expiresAfter(self::REUSE_SECONDS);
            $this->di['cache']->save($item);
        } catch (Throwable) {
            // Without the cache a new checkout is made next time; nothing else depends on it.
        }
    }

    private function log(string $level, string $format, string ...$args): void
    {
        if (isset($this->di['logger'])) {
            $this->di['logger']->{$level}($format, ...$args);
        }
    }

    private function t(string $text, array $vars = []): string
    {
        return function_exists('__trans') ? (string) __trans($text, $vars) : strtr($text, $vars);
    }

    private function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function message(string $text, array $vars = []): string
    {
        return '<div class="alert alert-info" role="status">' . $this->esc($this->t($text, $vars)) . '</div>';
    }

    private function button(string $url, string $amount, string $currency): string
    {
        $html = '<div class="plaxswap-pay">';
        if (!$this->isLive()) {
            $html .= '<div class="alert alert-warning" role="status">' . $this->esc($this->t('Test mode: no real coins move.')) . '</div>';
        }
        $html .= '<p>' . $this->esc($this->t('Pay :amount :currency with Bitcoin, USDT, USDC, ETH, SOL, LTC or another coin on Plaxswap\'s checkout.', [':amount' => Decimal::forDisplay($amount, $currency), ':currency' => $currency])) . '</p>'
            . '<a class="btn btn-primary" id="plaxswap-pay-button" href="' . $this->esc($url) . '" target="_top" rel="noopener">' . $this->esc($this->t('Pay with crypto')) . '</a>'
            . '<p class="small text-muted mt-2">' . $this->esc($this->t('The checkout shows the 1.5% Plaxswap fee and the network costs of your coin before you pay. The invoice is marked paid when Plaxswap confirms the payment.')) . '</p>'
            . '</div>';
        if (!empty($this->config['auto_redirect'])) {
            $html .= '<script>window.top.location.href = ' . json_encode($url, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) . ';</script>';
        }

        return $html;
    }
}
