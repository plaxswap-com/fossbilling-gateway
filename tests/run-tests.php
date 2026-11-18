<?php

declare(strict_types=1);
/**
 * Plain PHP tests for the Plaxswap FOSSBilling adapter. No Composer, no network, no database.
 * Run: /opt/homebrew/bin/php tests/run-tests.php
 */

require __DIR__ . '/stubs.php';
require __DIR__ . '/../src/Plaxswap/Plaxswap.php';

use Plaxswap\FOSSBilling\Decimal;
use Plaxswap\FOSSBilling\Signature;

// Made-up values in the documented formats. They are not real keys.
const TEST_KEY = 'pxs_test_abcdefghij_TTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTTT';
const LIVE_KEY = 'pxs_live_abcdefghij_LLLLLLLLLLLLLLLLLLLLLLLLLLLLLLLLLLLLLLLL';
const SECRET = 'whsec_ssssssssssssssssssssssssssssssss';
const GATEWAY = 7;
const INVOICE = 42;

$passed = 0;
$failed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? ++$passed : ++$failed;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : '  [' . $detail . ']') . "\n";
}

function section(string $title): void
{
    echo "\n== {$title}\n";
}

/** A FOSSBilling-like world: one client, one unpaid 49.50 USD invoice, the gateway's services and a fake Plaxswap. */
function world(bool $testMode = true, array $override = []): object
{
    $w = new stdClass();
    $w->db = new FakeDb();
    $w->invoices = new FakeInvoiceService($w->db);
    $w->transactions = new FakeTransactionService($w->db);
    $w->clients = new FakeClientService();
    $w->logger = new FakeLogger();
    $w->cache = new FakeCache();
    $w->plaxswap = new FakePlaxswap();

    $client = new Model_Client();
    $client->id = 3;
    $client->balance = 0.0;
    $w->db->add('Client', $client);
    $invoice = new Model_Invoice();
    $invoice->id = INVOICE;
    $invoice->client_id = 3;
    $invoice->currency = 'USD';
    $invoice->total = 49.5;
    $invoice->status = Model_Invoice::STATUS_UNPAID;
    $invoice->approved = true;
    $invoice->serie = 'INV-';
    $invoice->nr = '00042';
    $w->db->add('Invoice', $invoice);
    $w->invoice = $invoice;
    $w->client = $client;

    $di = new Pimple\Container();
    $di['db'] = $w->db;
    $di['logger'] = $w->logger;
    $di['cache'] = $w->cache;
    $di['dbal'] = new FakeDbal();
    $di['http_client'] = $w->plaxswap;
    $di['mod_service'] = function (string $mod, string $sub = '') use ($w) {
        return match (strtolower($mod . '/' . $sub)) {
            'invoice/' => $w->invoices,
            'invoice/transaction' => $w->transactions,
            'client/' => $w->clients,
        };
    };
    $w->di = $di;

    // The config FOSSBilling builds in ServicePayGateway::getPaymentAdapter() for an invoice payment.
    $w->config = array_merge([
        'api_key' => LIVE_KEY,
        'test_api_key' => TEST_KEY,
        'webhook_secret' => SECRET,
        'test_mode' => $testMode,
        'gateway_id' => GATEWAY,
        'auto_redirect' => false,
        'return_url' => 'https://billing.example.com/invoice/payment-return?status=ok&hash=abc123',
        'cancel_url' => 'https://billing.example.com/invoice/payment-return?status=cancel&hash=abc123',
        'notify_url' => 'https://billing.example.com/ipn.php?gateway_id=7&invoice_id=42',
        'redirect_url' => 'https://billing.example.com/ipn.php?gateway_id=7&invoice_id=42&invoice_hash=abc123&redirect=1',
    ], $override);
    $w->adapter = adapterFor($w, $w->config);

    return $w;
}

function adapterFor(object $w, array $config): Payment_Adapter_Plaxswap
{
    $adapter = new Payment_Adapter_Plaxswap($config);
    $adapter->setDi($w->di);

    return $adapter;
}

/** The adapter FOSSBilling builds for ipn.php (no invoice in the URLs). */
function ipnAdapter(object $w): Payment_Adapter_Plaxswap
{
    $config = $w->config;
    $config['notify_url'] = 'https://billing.example.com/ipn.php?gateway_id=7';
    $config['redirect_url'] = 'https://billing.example.com/ipn.php?gateway_id=7';

    return adapterFor($w, $config);
}

function lastPaymentId(object $w): string
{
    return array_key_last($w->plaxswap->payments);
}

function webhook(array $payment, string $event, bool $live = false, ?int $t = null, string $secret = SECRET, ?string $eventId = null): array
{
    $body = json_encode([
        'id' => $eventId ?? 'evt_' . substr(hash('sha256', $payment['id'] . $event . $payment['status']), 0, 16),
        'event' => $event,
        'livemode' => $live,
        'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z'),
        'data' => $payment,
    ], JSON_UNESCAPED_SLASHES);
    $t ??= time();

    return [
        'source' => 'ipn',
        'get' => ['gateway_id' => (string) GATEWAY],
        'post' => [],
        'server' => ['HTTP_X_PX_SIGNATURE' => 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret), 'CONTENT_TYPE' => 'application/json'],
        'http_raw_post_data' => $body,
    ];
}

/**
 * What ipn.php + ServiceTransaction::createAndProcess() do: store a "received" transaction, call processTransaction(),
 * and mark the transaction as an error if it throws. Returns [transaction id, error message or null].
 */
function deliver(object $w, array $data, ?int $invoiceId = null, ?string $receivedAt = null): array
{
    $tx = new Model_Transaction();
    $tx->gateway_id = GATEWAY;
    $tx->invoice_id = $invoiceId;
    $tx->txn_id = null;
    $tx->status = Model_Transaction::STATUS_RECEIVED;
    $tx->ipn = json_encode($data);
    $tx->created_at = $receivedAt ?? date('Y-m-d H:i:s');
    $w->db->add('Transaction', $tx);
    $error = null;

    try {
        ipnAdapter($w)->processTransaction(null, (int) $tx->id, $data, GATEWAY);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $row = $w->db->load('Transaction', $tx->id);
        if ($row && $row->status !== Model_Transaction::STATUS_PROCESSED) {
            $row->status = Model_Transaction::STATUS_ERROR;
            $row->error = $error;
        }
    }

    return [(int) $tx->id, $error];
}

function tx(object $w, int $id): ?object
{
    return $w->db->load('Transaction', $id);
}

function startCheckout(object $w): string
{
    return $w->adapter->getHtml(null, INVOICE, false);
}

// ---------------------------------------------------------------------------------------------------------------
section('Configuration');
$w = world();
check('getConfig declares one-time payments only', Payment_Adapter_Plaxswap::getConfig()['supports_one_time_payments'] === true && Payment_Adapter_Plaxswap::getConfig()['supports_subscriptions'] === false);
$form = Payment_Adapter_Plaxswap::getConfig()['form'];
check('form has live key, test key and webhook secret, all marked secret', array_keys($form) === ['api_key', 'test_api_key', 'webhook_secret'] && $form['api_key'][1]['secret'] && $form['test_api_key'][1]['secret'] && $form['webhook_secret'][1]['secret']);
foreach ([
    'missing test key in test mode' => ['test_api_key' => ''],
    'live key used as test key' => ['test_api_key' => LIVE_KEY],
    'missing webhook secret' => ['webhook_secret' => ''],
    'API key pasted as webhook secret' => ['webhook_secret' => TEST_KEY],
] as $name => $bad) {
    $threw = false;
    try {
        new Payment_Adapter_Plaxswap(array_merge($w->config, $bad));
    } catch (Payment_Exception) {
        $threw = true;
    }
    check('constructor refuses: ' . $name, $threw);
}
$threw = false;
try {
    new Payment_Adapter_Plaxswap(array_merge($w->config, ['test_mode' => false, 'api_key' => TEST_KEY]));
} catch (Payment_Exception) {
    $threw = true;
}
check('constructor refuses: test key as live key with test mode off', $threw);
check('Decimal: 49.5 USD -> "49.5", 1200 JPY -> "1200", 1.2345 KWD -> "1.235"', Decimal::fromInvoiceTotal(49.5, 'USD') === '49.5' && Decimal::fromInvoiceTotal(1200.0, 'JPY') === '1200' && Decimal::fromInvoiceTotal(1.2345, 'KWD') === '1.235');
check('Display: "49.5" USD -> "49.50", "1200" JPY -> "1200", "7" KWD -> "7.000"', Decimal::forDisplay('49.5', 'USD') === '49.50' && Decimal::forDisplay('1200', 'JPY') === '1200' && Decimal::forDisplay('7', 'KWD') === '7.000');

// ---------------------------------------------------------------------------------------------------------------
section('Payment request (getHtml)');
$w = world();
$html = startCheckout($w);
$request = $w->plaxswap->requests[0] ?? null;
$body = json_decode($request['options']['body'] ?? 'null', true);
check('one POST to https://plaxswap.com/api/v1/payments', count($w->plaxswap->requests) === 1 && $request['method'] === 'POST' && $request['url'] === 'https://plaxswap.com/api/v1/payments');
check('Authorization: Bearer <test key> in test mode', ($request['options']['headers']['Authorization'] ?? '') === 'Bearer ' . TEST_KEY);
check('Content-Type is application/json, no redirects followed', ($request['options']['headers']['Content-Type'] ?? '') === 'application/json' && $request['options']['max_redirects'] === 0);
check('amount "49.5" and currency "USD" from the invoice total', $body['amount'] === '49.5' && $body['currency'] === 'USD');
check('receiveMode original, customer pays the fee, 60 minute expiry', $body['receiveMode'] === 'original' && $body['customerPaysFee'] === true && $body['expiresInMinutes'] === 60);
check('orderId is FB-<site>-<invoice>-<random> and equals the Idempotency-Key', (bool) preg_match('/^FB-[a-f0-9]{8}-42-[a-f0-9]{8}$/', $body['orderId']) && $request['options']['headers']['Idempotency-Key'] === $body['orderId']);
check('description names the invoice', $body['description'] === 'Invoice INV-00042');
check('returnUrl is the FOSSBilling redirect URL plus plaxswap_ref', $body['returnUrl'] === $w->config['redirect_url'] . '&plaxswap_ref=' . $body['orderId']);
check('cancelUrl is the FOSSBilling cancel URL', $body['cancelUrl'] === $w->config['cancel_url']);
check('metadata binds invoice, gateway and site, with a 64-hex verification', $body['metadata']['integration'] === 'fossbilling' && $body['metadata']['fossbilling_invoice'] === '42' && $body['metadata']['fossbilling_gateway'] === '7' && (bool) preg_match('/^[a-f0-9]{8}$/', $body['metadata']['fossbilling_site']) && (bool) preg_match('/^[a-f0-9]{64}$/', $body['metadata']['fossbilling_verification']));
check('no customer email or name is sent', !isset($body['customer']));
$payId = lastPaymentId($w);
check('HTML links to the Plaxswap checkout of the new payment', str_contains($html, 'href="https://plaxswap.com/checkout/' . $payId . '"'));
check('HTML shows the amount as 49.50 USD', str_contains($html, 'Pay 49.50 USD with'));
check('HTML says test mode', str_contains($html, 'Test mode: no real coins move.'));
check('HTML has no em dash', !str_contains($html, "\u{2014}"));
$html2 = startCheckout($w);
check('opening the pay page again reuses the payment (one GET, no second POST)', count($w->plaxswap->requests) === 2 && $w->plaxswap->requests[1]['method'] === 'GET' && str_contains($html2, $payId));
$w->plaxswap->outcome($payId, 'expired');
startCheckout($w);
check('an expired payment is replaced by a new one', count($w->plaxswap->payments) === 2);
$w->plaxswap->down = true;
$w->cache->items = [];
$html = startCheckout($w);
check('Plaxswap unreachable: friendly message, no exception', str_contains($html, 'Crypto checkout is unavailable right now'));
$w = world(false);
startCheckout($w);
check('live mode uses the live key', ($w->plaxswap->requests[0]['options']['headers']['Authorization'] ?? '') === 'Bearer ' . LIVE_KEY);
$w = world();
$w->invoice->currency = 'XYZ';
check('unsupported currency: message, no request', str_contains(startCheckout($w), 'not available for invoices in XYZ') && !$w->plaxswap->requests);
$w = world();
$w->invoice->status = Model_Invoice::STATUS_PAID;
check('paid invoice: message, no request', str_contains(startCheckout($w), 'already paid') && !$w->plaxswap->requests);

// ---------------------------------------------------------------------------------------------------------------
section('Webhook signature');
$raw = '{"id":"evt_aaaaaaaaaaaaaaaa"}';
$t = time();
$good = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $raw, SECRET);
check('valid signature passes', Signature::verify($raw, $good, [SECRET], $t));
check('signature made with another secret fails', !Signature::verify($raw, 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $raw, 'whsec_other_secret_xxxxxxxxxxxxxxxx'), [SECRET], $t));
check('changed body fails', !Signature::verify($raw . ' ', $good, [SECRET], $t));
check('timestamp older than 5 minutes fails', !Signature::verify($raw, $good, [SECRET], $t + 301));
check('timestamp exactly 5 minutes old passes', Signature::verify($raw, $good, [SECRET], $t + 300));
check('several v1 values: one match is enough', Signature::verify($raw, 't=' . $t . ',v1=' . str_repeat('0', 64) . ',v1=' . hash_hmac('sha256', $t . '.' . $raw, SECRET), [SECRET], $t));
check('missing or malformed header fails', !Signature::verify($raw, '', [SECRET], $t) && !Signature::verify($raw, 'v1=' . hash_hmac('sha256', $t . '.' . $raw, SECRET), [SECRET], $t) && !Signature::verify($raw, 't=' . $t . ',v1=XYZ', [SECRET], $t));

$w = world();
startCheckout($w);
$paid = $w->plaxswap->outcome(lastPaymentId($w), 'paid');
[$id, $error] = deliver($w, webhook($paid, 'payment.paid', false, time(), 'whsec_wrongwrongwrongwrongwrongwrong'));
check('webhook with a bad signature is refused and kept as an error', $error !== null && str_contains($error, 'signature') && tx($w, $id)->status === Model_Transaction::STATUS_ERROR);
check('... and the invoice is not paid', $w->invoice->status === Model_Invoice::STATUS_UNPAID && !$w->clients->funds);
[$id, $error] = deliver($w, webhook($paid, 'payment.paid', false, time() - 600));
check('webhook signed 10 minutes before it arrived is refused', $error !== null && $w->invoice->status === Model_Invoice::STATUS_UNPAID);
$old = webhook($paid, 'payment.paid', false, time() - 600);
[$id, $error] = deliver($w, $old, null, date('Y-m-d H:i:s', time() - 590));
check('admin re-processing a stored webhook that arrived fresh 10 minutes ago works', $error === null && $w->invoice->status === Model_Invoice::STATUS_PAID);

// ---------------------------------------------------------------------------------------------------------------
section('Paid, once');
$w = world();
startCheckout($w);
$payId = lastPaymentId($w);
$paid = $w->plaxswap->outcome($payId, 'paid');
$delivery = webhook($paid, 'payment.paid');
[$id, $error] = deliver($w, $delivery);
$row = tx($w, $id);
check('payment.paid marks the invoice paid', $error === null && $w->invoice->status === Model_Invoice::STATUS_PAID, (string) $error);
check('client credited 49.5 once and the credit used for the invoice', count($w->clients->funds) === 1 && $w->clients->funds[0][1] === 49.5 && abs($w->client->balance) < 0.0001);
check('transaction processed with txn_id, invoice, amount and currency', $row && $row->status === Model_Transaction::STATUS_PROCESSED && $row->txn_id === $payId && (int) $row->invoice_id === INVOICE && $row->amount === '49.5' && $row->currency === 'USD');
check('the adapter read the payment back from the API before paying', (bool) array_filter($w->plaxswap->requests, fn ($r) => $r['method'] === 'GET' && str_ends_with($r['url'], '/api/v1/payments/' . $payId)));
check('a named lock was taken around the payment', (bool) array_filter($w->di['dbal']->locks, fn ($sql) => str_contains($sql, 'GET_LOCK')));
[$id2, $error2] = deliver($w, $delivery);
check('replayed webhook (same event, same signature) is ignored', $error2 === null && tx($w, $id2) === null && count($w->clients->funds) === 1 && $w->invoices->payCalls === 1);
[$id3, $error3] = deliver($w, webhook($paid, 'payment.updated', false, null, SECRET, 'evt_bbbbbbbbbbbbbbbb'));
check('a later event for the same paid payment does not pay again', $error3 === null && tx($w, $id3) === null && count($w->clients->funds) === 1);
[$id4, $error4] = deliver($w, ['source' => 'ipn', 'get' => ['gateway_id' => '7', 'invoice_id' => '42', 'redirect' => '1', 'plaxswap_ref' => $paid['orderId']], 'post' => [], 'server' => [], 'http_raw_post_data' => ''], INVOICE);
check('the client coming back from the checkout afterwards does not pay again', $error4 === null && tx($w, $id4) === null && count($w->clients->funds) === 1);
check('success is logged', (bool) array_filter($w->logger->lines, fn ($l) => str_contains($l, 'paid invoice #42')));

section('Paid on return, before the webhook');
$w = world();
startCheckout($w);
$paid = $w->plaxswap->outcome(lastPaymentId($w), 'paid');
[$id, $error] = deliver($w, ['source' => 'ipn', 'get' => ['gateway_id' => '7', 'invoice_id' => '42', 'redirect' => '1', 'plaxswap_ref' => $paid['orderId']], 'post' => [], 'server' => [], 'http_raw_post_data' => ''], INVOICE);
check('checkout return finds the paid payment through the API and pays the invoice', $error === null && $w->invoice->status === Model_Invoice::STATUS_PAID, (string) $error);
[$id2, $error2] = deliver($w, webhook($paid, 'payment.paid'));
check('the webhook that follows is ignored', $error2 === null && tx($w, $id2) === null && count($w->clients->funds) === 1);
[$id3, $error3] = deliver($w, ['source' => 'ipn', 'get' => ['gateway_id' => '7', 'invoice_id' => '41', 'redirect' => '1', 'plaxswap_ref' => $paid['orderId']], 'post' => [], 'server' => [], 'http_raw_post_data' => ''], 41);
check('a return naming another invoice is ignored', $error3 === null && tx($w, $id3) === null);

// ---------------------------------------------------------------------------------------------------------------
section('Not paid: underpaid, expired, failed, late, under review');
foreach ([
    ['partially_paid', 'payment.partially_paid', [], 'Underpaid'],
    ['paid', 'payment.paid', ['late' => true], 'Late payment'],
    ['paid', 'payment.paid', ['underReview' => true], 'compliance review'],
] as [$status, $event, $extra, $expect]) {
    $w = world();
    startCheckout($w);
    $payment = $w->plaxswap->outcome(lastPaymentId($w), $status, $extra);
    [$id, $error] = deliver($w, webhook($payment, $event));
    $row = tx($w, $id);
    check("{$event} " . json_encode($extra) . ': invoice stays unpaid, no credit', $w->invoice->status === Model_Invoice::STATUS_UNPAID && !$w->clients->funds);
    check("... kept as an error that says \"{$expect}\" and is logged", $row && $row->status === Model_Transaction::STATUS_ERROR && str_contains((string) $row->error, $expect) && (bool) array_filter($w->logger->lines, fn ($l) => str_contains($l, $expect)), (string) ($row->error ?? 'no row'));
    if ($status === 'partially_paid') {
        check('... the transaction does not show the full invoice amount', $row && $row->amount === null && $row->currency === null);
    }
    [$id2] = deliver($w, webhook($payment, $event));
    check('... a repeat of it is not stored twice', tx($w, $id2) === null);
}
foreach (['expired', 'failed', 'cancelled'] as $status) {
    $w = world();
    startCheckout($w);
    $payment = $w->plaxswap->outcome(lastPaymentId($w), $status);
    [$id, $error] = deliver($w, webhook($payment, 'payment.' . $status));
    check("payment.{$status}: invoice stays unpaid, transaction removed, logged", $error === null && $w->invoice->status === Model_Invoice::STATUS_UNPAID && !$w->clients->funds && tx($w, $id) === null && (bool) array_filter($w->logger->lines, fn ($l) => str_contains($l, "is {$status}; the invoice stays unpaid")));
}
$w = world();
startCheckout($w);
$payment = $w->plaxswap->outcome(lastPaymentId($w), 'confirming');
[$id, $error] = deliver($w, webhook($payment, 'payment.confirming'));
check('payment.confirming: nothing paid yet', $error === null && $w->invoice->status === Model_Invoice::STATUS_UNPAID && tx($w, $id) === null);
$webhookSaysPaid = $payment;
$webhookSaysPaid['status'] = 'paid';
[$id, $error] = deliver($w, webhook($webhookSaysPaid, 'payment.paid'));
check('a webhook body claiming "paid" while the API says confirming does not pay', $w->invoice->status === Model_Invoice::STATUS_UNPAID && !$w->clients->funds);

// ---------------------------------------------------------------------------------------------------------------
section('Wrong currency or amount');
$w = world();
startCheckout($w);
$payId = lastPaymentId($w);
$w->plaxswap->outcome($payId, 'paid', ['currency' => 'EUR']);
[$id, $error] = deliver($w, webhook($w->plaxswap->payments[$payId], 'payment.paid'));
check('API answer in another currency than the one signed: refused, not paid', $error !== null && str_contains($error, 'does not match') && $w->invoice->status === Model_Invoice::STATUS_UNPAID && !$w->clients->funds, (string) $error);

$w = world();
startCheckout($w);
$payId = lastPaymentId($w);
$w->plaxswap->outcome($payId, 'paid', ['amount' => '4.95']);
[$id, $error] = deliver($w, webhook($w->plaxswap->payments[$payId], 'payment.paid'));
check('API answer with another amount than the one signed: refused, not paid', $error !== null && str_contains($error, 'does not match') && $w->invoice->status === Model_Invoice::STATUS_UNPAID && !$w->clients->funds, (string) $error);

$w = world();
startCheckout($w);
$w->invoice->total = 99.0; // the admin added an item after the client opened the checkout
$payment = $w->plaxswap->outcome(lastPaymentId($w), 'paid');
[$id, $error] = deliver($w, webhook($payment, 'payment.paid'));
check('invoice total raised after the payment was made: not paid, error says Wrong amount', $w->invoice->status === Model_Invoice::STATUS_UNPAID && !$w->clients->funds && str_contains((string) tx($w, $id)->error, 'Wrong amount'), (string) (tx($w, $id)->error ?? $error));

$w = world();
startCheckout($w);
$w->invoice->currency = 'EUR';
$payment = $w->plaxswap->outcome(lastPaymentId($w), 'paid');
[$id, $error] = deliver($w, webhook($payment, 'payment.paid'));
check('invoice currency changed after the payment was made: not paid, error says Wrong currency', $w->invoice->status === Model_Invoice::STATUS_UNPAID && !$w->clients->funds && str_contains((string) tx($w, $id)->error, 'Wrong currency'));

$w = world();
startCheckout($w);
$first = $w->plaxswap->outcome(lastPaymentId($w), 'paid');
deliver($w, webhook($first, 'payment.paid'));
$w->cache->items = [];
$w->invoice->status = Model_Invoice::STATUS_UNPAID; // a second checkout opened before the first one was recorded
startCheckout($w);
$w->invoice->status = Model_Invoice::STATUS_PAID;
$second = $w->plaxswap->outcome(lastPaymentId($w), 'paid');
[$id, $error] = deliver($w, webhook($second, 'payment.paid'));
check('a second paid payment for an already paid invoice is not applied, admin is told', count($w->clients->funds) === 1 && str_contains((string) tx($w, $id)->error, 'already paid'));

// ---------------------------------------------------------------------------------------------------------------
section('Events that are not for this gateway');
$w = world();
startCheckout($w);
$payment = $w->plaxswap->outcome(lastPaymentId($w), 'paid');
[$id, $error] = deliver($w, webhook($payment, 'payment.paid', true));
check('live event while the gateway is in test mode: ignored', $error === null && tx($w, $id) === null && $w->invoice->status === Model_Invoice::STATUS_UNPAID);
$ping = ['source' => 'ipn', 'get' => [], 'post' => [], 'server' => [], 'http_raw_post_data' => ''];
$pingBody = json_encode(['id' => 'evt_cccccccccccccccc', 'event' => 'ping', 'livemode' => false, 'createdAt' => gmdate('Y-m-d\TH:i:s\Z'), 'data' => ['ping' => true, 'merchantId' => 'mer_b3hkgahs55a2']]);
$ping['http_raw_post_data'] = $pingBody;
$ping['server']['HTTP_X_PX_SIGNATURE'] = 't=' . time() . ',v1=' . hash_hmac('sha256', time() . '.' . $pingBody, SECRET);
[$id, $error] = deliver($w, $ping);
check('dashboard "Send test" ping: accepted, nothing stored', $error === null && tx($w, $id) === null && (bool) array_filter($w->logger->lines, fn ($l) => str_contains($l, 'test webhook received')));
$other = world(true, ['notify_url' => 'https://other-shop.example/ipn.php?gateway_id=7&invoice_id=42']);
startCheckout($other);
$foreign = $other->plaxswap->outcome(array_key_last($other->plaxswap->payments), 'paid');
$foreign['id'] = 'pay_othershop0000001'; // same Plaxswap account, so the API returns it to this site too
$w->plaxswap->payments[$foreign['id']] = $foreign;
[$id, $error] = deliver($w, webhook($foreign, 'payment.paid'));
check('payment made by another FOSSBilling site on the same Plaxswap account: ignored', $error === null && tx($w, $id) === null && $w->invoice->status === Model_Invoice::STATUS_UNPAID);
[$id, $error] = deliver($w, ['source' => 'ipn', 'get' => ['gateway_id' => '7'], 'post' => ['x' => '1'], 'server' => [], 'http_raw_post_data' => 'x=1']);
check('unsigned non-JSON callback: removed, nothing paid', $error === null && tx($w, $id) === null && $w->invoice->status === Model_Invoice::STATUS_UNPAID);
$w = world();
startCheckout($w);
$payment = $w->plaxswap->outcome(lastPaymentId($w), 'paid');
$w->plaxswap->down = true;
[$id, $error] = deliver($w, webhook($payment, 'payment.paid'));
check('Plaxswap API down during a webhook: kept as an error to process again, not paid', $error !== null && str_contains($error, 'Process this transaction again') && tx($w, $id)->status === Model_Transaction::STATUS_ERROR && $w->invoice->status === Model_Invoice::STATUS_UNPAID);
$w->plaxswap->down = false;
$retry = tx($w, $id);
ipnAdapter($w)->processTransaction(null, $id, json_decode($retry->ipn, true), GATEWAY);
check('... and pressing Process later pays it once', $w->invoice->status === Model_Invoice::STATUS_PAID && count($w->clients->funds) === 1);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
