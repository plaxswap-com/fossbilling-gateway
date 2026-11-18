<?php

declare(strict_types=1);
/**
 * Stand-ins for the parts of FOSSBilling 0.8.9 the Plaxswap adapter touches, plus a fake Plaxswap API server.
 * These are NOT FOSSBilling: they copy the behaviour the adapter relies on (src/library/Payment/Adapter/Stripe.php,
 * src/modules/Invoice/ServiceTransaction.php and Service.php at tag 0.8.9) so the adapter can run with plain PHP.
 */

namespace FOSSBilling {
    interface InjectionAwareInterface
    {
        public function setDi(\Pimple\Container $di): void;

        public function getDi(): ?\Pimple\Container;
    }

    class Exception extends \Exception
    {
        public function __construct(string $message = '', ?array $variables = null, int $code = 0)
        {
            parent::__construct($variables ? strtr($message, $variables) : $message, $code);
        }
    }
}

namespace Pimple {
    class Container implements \ArrayAccess
    {
        private array $values = [];

        public function offsetExists(mixed $offset): bool
        {
            return array_key_exists($offset, $this->values);
        }

        public function offsetGet(mixed $offset): mixed
        {
            return $this->values[$offset];
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            $this->values[$offset] = $value;
        }

        public function offsetUnset(mixed $offset): void
        {
            unset($this->values[$offset]);
        }
    }
}

namespace {
    class Payment_Exception extends FOSSBilling\Exception
    {
    }

    #[AllowDynamicProperties]
    class Model_Transaction
    {
        public const STATUS_RECEIVED = 'received';
        public const STATUS_APPROVED = 'approved';
        public const STATUS_PROCESSING = 'processing';
        public const STATUS_PROCESSED = 'processed';
        public const STATUS_ERROR = 'error';
    }

    #[AllowDynamicProperties]
    class Model_Invoice
    {
        public const STATUS_PAID = 'paid';
        public const STATUS_UNPAID = 'unpaid';
    }

    #[AllowDynamicProperties]
    class Model_Client
    {
    }

    /** In-memory RedBean-like store: load, getExistingModelById, findOne (simple AND/=/!=/IN), store, trash. */
    class FakeDb
    {
        public array $rows = ['Transaction' => [], 'Invoice' => [], 'Client' => []];
        public array $trashed = [];
        private int $nextId = 1;

        public function add(string $type, object $model): object
        {
            if (empty($model->id)) {
                $model->id = $this->nextId++;
            }
            $this->rows[$type][$model->id] = $model;

            return $model;
        }

        public function load(string $type, $id): ?object
        {
            return $this->rows[$type][(int) $id] ?? null;
        }

        public function getExistingModelById(string $type, $id, string $message = 'not found'): object
        {
            $model = $this->load($type, $id);
            if ($model === null) {
                throw new FOSSBilling\Exception($message);
            }

            return $model;
        }

        public function findOne(string $type, string $where, array $params = []): ?object
        {
            $conditions = array_map('trim', explode(' AND ', $where));
            foreach ($this->rows[$type] as $row) {
                $match = true;
                foreach ($conditions as $condition) {
                    if (preg_match('/^(\w+) (=|!=) (:\w+)$/', $condition, $m)) {
                        $value = $params[$m[3]] ?? null;
                        $equal = (string) ($row->{$m[1]} ?? '') === (string) $value;
                        $match = $match && ($m[2] === '=' ? $equal : !$equal);
                    } elseif (preg_match('/^(\w+) IN \((.*)\)$/', $condition, $m)) {
                        $allowed = array_map(fn ($p) => (string) $params[trim($p)], explode(',', $m[2]));
                        $match = $match && in_array((string) ($row->{$m[1]} ?? ''), $allowed, true);
                    } else {
                        throw new LogicException('FakeDb cannot read: ' . $condition);
                    }
                }
                if ($match) {
                    return $row;
                }
            }

            return null;
        }

        public function store(object $model): int
        {
            $type = substr($model::class, strlen('Model_'));
            $this->add($type, $model);

            return (int) $model->id;
        }

        public function trash(object $model): void
        {
            $type = substr($model::class, strlen('Model_'));
            unset($this->rows[$type][$model->id]);
            $this->trashed[] = $model->id;
        }
    }

    class FakeInvoiceService
    {
        public int $payCalls = 0;

        public function __construct(private FakeDb $db)
        {
        }

        public function getTotalWithTax(Model_Invoice $invoice): float
        {
            return (float) $invoice->total;
        }

        /** Same rule as FOSSBilling 0.8.9 Box\Mod\Invoice\Service::validatePaymentAmount (0.01 below the total fails). */
        public function validatePaymentAmount(float $received, float $expected): void
        {
            if ($received < $expected - 0.01) {
                throw new FOSSBilling\Exception('Payment amount does not match the expected invoice total. Expected :expected, received :received.', [':expected' => number_format($expected, 2, '.', ''), ':received' => number_format($received, 2, '.', '')]);
            }
        }

        public function isInvoiceTypeDeposit(Model_Invoice $invoice): bool
        {
            return false;
        }

        public function approveInvoice(Model_Invoice $invoice, array $data): bool
        {
            $invoice->approved = true;

            return true;
        }

        public function payInvoiceWithCredits(Model_Invoice $invoice): bool
        {
            $client = $this->db->load('Client', $invoice->client_id);
            if ($client->balance + 0.000001 < $invoice->total) {
                return false;
            }
            $client->balance -= $invoice->total;
            $invoice->status = Model_Invoice::STATUS_PAID;
            ++$this->payCalls;

            return true;
        }

        public function markAsPaid(Model_Invoice $invoice): bool
        {
            $invoice->status = Model_Invoice::STATUS_PAID;
            ++$this->payCalls;

            return true;
        }
    }

    class FakeTransactionService
    {
        public function __construct(private FakeDb $db)
        {
        }

        /** Same rule as ServiceTransaction::claimForProcessing: only received or error rows can be claimed. */
        public function claimForProcessing(int $id): bool
        {
            $tx = $this->db->load('Transaction', $id);
            if (!$tx || !in_array($tx->status, [Model_Transaction::STATUS_RECEIVED, Model_Transaction::STATUS_ERROR], true)) {
                return false;
            }
            $tx->status = Model_Transaction::STATUS_PROCESSING;

            return true;
        }
    }

    class FakeClientService
    {
        public array $funds = [];

        public function addFunds(Model_Client $client, $amount, $description, array $data = []): bool
        {
            $client->balance += (float) $amount;
            $this->funds[] = [$client->id, (float) $amount, $description];

            return true;
        }
    }

    class FakeLogger
    {
        public array $lines = [];

        public function __call(string $level, array $args): void
        {
            $format = (string) array_shift($args);
            $this->lines[] = $level . ': ' . ($args ? vsprintf($format, $args) : $format);
        }
    }

    class FakeCacheItem
    {
        public mixed $value = null;
        public bool $hit = false;

        public function __construct(public string $key)
        {
        }

        public function isHit(): bool
        {
            return $this->hit;
        }

        public function get(): mixed
        {
            return $this->value;
        }

        public function set(mixed $value): static
        {
            $this->value = $value;

            return $this;
        }

        public function expiresAfter($seconds): static
        {
            return $this;
        }
    }

    class FakeCache
    {
        public array $items = [];

        public function getItem(string $key): FakeCacheItem
        {
            return $this->items[$key] ?? new FakeCacheItem($key);
        }

        public function save(FakeCacheItem $item): bool
        {
            $item->hit = true;
            $this->items[$item->key] = $item;

            return true;
        }
    }

    class FakeDbal
    {
        public array $locks = [];

        public function fetchOne(string $sql, array $params = []): int
        {
            $this->locks[] = $sql;

            return 1;
        }
    }

    class FakeResponse
    {
        public function __construct(private int $status, private string $body)
        {
        }

        public function getStatusCode(): int
        {
            return $this->status;
        }

        public function getContent(bool $throw = true): string
        {
            return $this->body;
        }
    }

    /**
     * A mocked HTTP client that plays the Plaxswap payment API (POST/GET /api/v1/payments, GET /api/v1/payments/{id}).
     * It records every request so tests can check what the adapter sent. No network is used.
     */
    class FakePlaxswap
    {
        public array $requests = [];
        public array $payments = [];
        public bool $down = false;
        private int $counter = 0;

        public function request(string $method, string $url, array $options = []): FakeResponse
        {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            if ($this->down) {
                throw new RuntimeException('connection refused');
            }
            $auth = $options['headers']['Authorization'] ?? '';
            if (!preg_match('/^Bearer pxs_(live|test)_/', $auth, $m)) {
                return $this->json(401, ['error' => ['code' => 'invalid_api_key', 'message' => 'Invalid key']]);
            }
            $live = $m[1] === 'live';
            $parts = parse_url($url);
            $path = $parts['path'] ?? '';
            if ($method === 'POST' && $path === '/api/v1/payments') {
                $body = json_decode($options['body'] ?? '', true);
                $id = 'pay_' . str_pad(base_convert((string) (++$this->counter), 10, 36), 16, 'x', STR_PAD_LEFT);
                $this->payments[$id] = [
                    'id' => $id, 'object' => 'payment', 'livemode' => $live, 'status' => 'pending',
                    'amount' => $body['amount'], 'currency' => $body['currency'], 'receiveMode' => $body['receiveMode'],
                    'receive' => ['id' => 'usdt-tron'], 'receiveAmount' => $body['amount'], 'expectedAmount' => $body['amount'],
                    'receivedAmount' => '0', 'receivedBalances' => [], 'fee' => ['bps' => 150, 'paidBy' => 'customer', 'amount' => '0.74'],
                    'orderId' => $body['orderId'], 'description' => $body['description'], 'metadata' => $body['metadata'],
                    'checkoutUrl' => 'https://plaxswap.com/checkout/' . $id, 'returnUrl' => $body['returnUrl'] ?? null,
                    'cancelUrl' => $body['cancelUrl'] ?? null, 'late' => false, 'overpaid' => false, 'underReview' => false,
                    'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z'), 'expiresAt' => gmdate('Y-m-d\TH:i:s.000\Z', time() + 3600), 'paidAt' => null,
                ];

                return $this->json(201, $this->payments[$id]);
            }
            if ($method === 'GET' && preg_match('#^/api/v1/payments/(pay_[a-z0-9]{16})$#', $path, $p)) {
                $payment = $this->payments[$p[1]] ?? null;

                return $payment && $payment['livemode'] === $live ? $this->json(200, $payment) : $this->json(404, ['error' => ['code' => 'not_found', 'message' => 'No such payment']]);
            }
            if ($method === 'GET' && $path === '/api/v1/payments') {
                parse_str($parts['query'] ?? '', $query);
                $rows = array_values(array_filter($this->payments, fn ($row) => $row['livemode'] === $live && $row['orderId'] === ($query['orderId'] ?? null)));

                return $this->json(200, ['data' => array_reverse($rows), 'hasMore' => false, 'nextCursor' => null]);
            }

            return $this->json(404, ['error' => ['code' => 'not_found', 'message' => 'Unknown route']]);
        }

        /** Acts out an outcome the way POST /api/v1/payments/{id}/simulate does on a real test payment. */
        public function outcome(string $id, string $status, array $extra = []): array
        {
            $payment = &$this->payments[$id];
            $payment['status'] = $status;
            if ($status === 'paid') {
                $payment['receivedAmount'] = $payment['expectedAmount'];
                $payment['paidAt'] = gmdate('Y-m-d\TH:i:s.000\Z');
            } elseif ($status === 'partially_paid') {
                $payment['receivedAmount'] = '20';
            }
            $payment = array_merge($payment, $extra);

            return $payment;
        }

        private function json(int $status, array $body): FakeResponse
        {
            return new FakeResponse($status, json_encode($body, JSON_UNESCAPED_SLASHES));
        }
    }
}
