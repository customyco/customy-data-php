<?php

declare(strict_types=1);

namespace Customy\Data;

final class CustomyDataClient
{
    public const VERSION = '0.1.1';
    public const CONFORMANCE_CONTRACT = 'customy.customer-data-sdk.conformance.v1';

    private const EVENT_TYPES = ['track', 'identify', 'group', 'page', 'screen', 'alias'];
    private const FORBIDDEN_TENANT_FIELDS = ['tenantId', 'organizationId', 'projectId', 'environmentId'];
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    /** @var list<array<string, mixed>> */
    private array $queue = [];
    private int $inFlightCount = 0;
    private bool $flushing = false;
    /** @var array<string, true> */
    private array $redactFieldSet;
    /** @var callable(string, array<string, string>, string, int): DataResponse */
    private $transport;
    /** @var callable(array<string, mixed>): ?array<string, mixed>|null */
    private $beforeSend;
    /** @var callable(): \DateTimeImmutable */
    private $now;
    /** @var callable(): string */
    private $idFactory;

    /**
     * @param callable(string, array<string, string>, string, int): DataResponse|null $transport
     * @param list<string> $redactFields
     * @param callable(array<string, mixed>): ?array<string, mixed>|null $beforeSend
     * @param callable(): \DateTimeImmutable|null $now
     * @param callable(): string|null $idFactory
     */
    public function __construct(
        private readonly string $collectUrl,
        private readonly string $writeKey,
        ?callable $transport = null,
        private readonly int $maxRetries = 3,
        private readonly int $retryBaseMs = 250,
        private readonly int $timeoutMs = 10_000,
        private readonly int $maxBatchSize = 100,
        private readonly int $maxQueueSize = 10_000,
        array $redactFields = [],
        ?callable $beforeSend = null,
        ?callable $now = null,
        ?callable $idFactory = null,
    ) {
        if (trim($collectUrl) === '' || trim($writeKey) === '') {
            throw new \InvalidArgumentException('collectUrl and writeKey are required');
        }
        if ($maxBatchSize < 1 || $maxBatchSize > 1_000 || $maxQueueSize < 1) {
            throw new \InvalidArgumentException('invalid batch or queue limit');
        }
        $this->transport = $transport ?? self::httpTransport(...);
        $this->redactFieldSet = array_fill_keys($redactFields, true);
        $this->beforeSend = $beforeSend;
        $this->now = $now ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->idFactory = $idFactory ?? static fn (): string => self::uuidV4();
    }

    public function queueSize(): int
    {
        return count($this->queue) + $this->inFlightCount;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function event(array $input): array
    {
        $normalized = self::deepCopy($input);
        $this->rejectTenantFields($normalized);
        $this->validate($normalized);
        $normalized['messageId'] ??= ($this->idFactory)();
        $normalized['timestamp'] ??= ($this->now)()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        $normalized['schemaVersion'] ??= '1.0';
        $normalized['properties'] ??= [];
        $normalized['traits'] ??= [];
        $normalized['consent'] ??= [];
        $context = isset($normalized['context']) && is_array($normalized['context']) ? self::deepCopy($normalized['context']) : [];
        $context['library'] = ['name' => 'customy-data-php', 'version' => self::VERSION];
        $normalized['context'] = $context;
        $normalized = $this->redact($normalized);
        if ($this->beforeSend !== null) {
            $normalized = ($this->beforeSend)(self::deepCopy($normalized))
                ?? throw new CustomyDataException('event blocked by beforeSend');
            $normalized = self::deepCopy($normalized);
            $this->rejectTenantFields($normalized);
            $this->validate($normalized);
            $normalized = $this->redact($normalized);
        }
        return $normalized;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function sendEvent(array $input): array
    {
        return $this->request('event', $this->event($input));
    }

    /** @param array<string, mixed> $properties @param array<string, mixed> $identity @return array<string, mixed> */
    public function track(string $name, array $properties, array $identity): array
    {
        return $this->sendEvent([...$identity, 'type' => 'track', 'event' => $name, 'properties' => $properties]);
    }

    /** @param array<string, mixed> $traits @param array<string, mixed> $identity @return array<string, mixed> */
    public function identify(array $traits, array $identity): array
    {
        return $this->sendEvent([...$identity, 'type' => 'identify', 'traits' => $traits]);
    }

    /** @param array<string, mixed> $traits @param array<string, mixed> $identity @return array<string, mixed> */
    public function group(array $traits, array $identity): array
    {
        return $this->sendEvent([...$identity, 'type' => 'group', 'traits' => $traits]);
    }

    /** @param array<string, mixed> $properties @param array<string, mixed> $identity @return array<string, mixed> */
    public function page(array $properties, array $identity): array
    {
        return $this->sendEvent([...$identity, 'type' => 'page', 'properties' => $properties]);
    }

    /** @param array<string, mixed> $properties @param array<string, mixed> $identity @return array<string, mixed> */
    public function screen(array $properties, array $identity): array
    {
        return $this->sendEvent([...$identity, 'type' => 'screen', 'properties' => $properties]);
    }

    /** @param array<string, mixed> $identity @return array<string, mixed> */
    public function alias(string $userId, string $previousId, array $identity = []): array
    {
        return $this->sendEvent([
            ...$identity,
            'type' => 'alias',
            'userId' => $userId,
            'anonymousId' => $previousId,
            'properties' => ['previousId' => $previousId],
        ]);
    }

    /** @param array<string, mixed> $input */
    public function enqueue(array $input): int
    {
        if ($this->queueSize() >= $this->maxQueueSize) {
            throw new CustomyDataException('customer data queue is full');
        }
        $this->queue[] = $this->event($input);
        return $this->queueSize();
    }

    /** @return array<string, mixed> */
    public function flush(): array
    {
        if ($this->flushing) {
            throw new CustomyDataException('a customer data flush is already in progress');
        }
        $this->flushing = true;
        $pending = self::deepCopy($this->queue);
        $this->queue = [];
        $this->inFlightCount = count($pending);
        try {
            if ($pending === []) {
                return self::emptyBatch();
            }
            $aggregate = self::emptyBatch();
            foreach (array_chunk($pending, $this->maxBatchSize) as $batch) {
                $response = $this->request('batch', ['batch' => $batch]);
                foreach (['accepted', 'deduplicated', 'quarantined'] as $key) {
                    $aggregate[$key] += is_numeric($response[$key] ?? null) ? (int) $response[$key] : 0;
                }
                $aggregate['results'] = [...$aggregate['results'], ...($response['results'] ?? [])];
            }
            $this->inFlightCount = 0;
            return $aggregate;
        } catch (\Throwable $error) {
            $this->queue = [...$pending, ...$this->queue];
            $this->inFlightCount = 0;
            throw $error;
        } finally {
            $this->flushing = false;
        }
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function request(string $path, array $payload): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = [
            'content-type' => 'application/json',
            'user-agent' => 'customy-data-php/'.self::VERSION,
            'x-write-key' => $this->writeKey,
        ];
        $lastError = null;
        for ($attempt = 0; $attempt <= max(0, $this->maxRetries); ++$attempt) {
            try {
                $response = ($this->transport)(rtrim($this->collectUrl, '/').'/v1/collect/'.$path, $headers, $body, $this->timeoutMs);
                $parsed = $response->body === '' ? [] : json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($parsed)) {
                    throw new CustomyDataException('expected a JSON object');
                }
                if ($response->statusCode >= 200 && $response->statusCode < 300) {
                    return $parsed;
                }
                throw new CustomyDataException(
                    "Customy Data collection failed with HTTP {$response->statusCode}",
                    $response->statusCode,
                    $parsed,
                );
            } catch (\Throwable $error) {
                $lastError = $error;
                if ($attempt >= max(0, $this->maxRetries) || !$this->retryable($error)) {
                    if ($error instanceof CustomyDataException) {
                        throw $error;
                    }
                    throw new CustomyDataException('Customy Data collection failed: '.$error->getMessage(), previous: $error);
                }
                usleep(max(0, $this->retryBaseMs) * (2 ** $attempt) * 1_000);
            }
        }
        throw new CustomyDataException('Customy Data collection failed: '.($lastError?->getMessage() ?? 'unknown'));
    }

    /** @param array<string, mixed> $event */
    private function validate(array $event): void
    {
        $type = $event['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::EVENT_TYPES, true)) {
            throw new \InvalidArgumentException('type must be track, identify, group, page, screen or alias');
        }
        if (!array_filter(['userId', 'anonymousId', 'groupId'], static fn (string $key): bool => self::present($event[$key] ?? null))) {
            throw new \InvalidArgumentException('at least one userId, anonymousId or groupId is required');
        }
        if ($type === 'track' && !self::present($event['event'] ?? null)) {
            throw new \InvalidArgumentException('track calls require an event name');
        }
    }

    /** @param array<string, mixed> $event */
    private function rejectTenantFields(array $event): void
    {
        $found = array_values(array_filter(self::FORBIDDEN_TENANT_FIELDS, static fn (string $key): bool => array_key_exists($key, $event)));
        sort($found);
        if ($found !== []) {
            throw new \InvalidArgumentException('tenant scope is derived from the write key; forbidden fields: '.implode(', ', $found));
        }
    }

    private function redact(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $result = [];
        foreach ($value as $key => $entry) {
            $result[$key] = isset($this->redactFieldSet[(string) $key]) ? '[REDACTED]' : $this->redact($entry);
        }
        return $result;
    }

    private function retryable(\Throwable $error): bool
    {
        return !$error instanceof CustomyDataException
            || $error->statusCode === null
            || in_array($error->statusCode, self::RETRYABLE_STATUSES, true);
    }

    private static function present(mixed $value): bool
    {
        return $value !== null && (!is_string($value) || $value !== '');
    }

    /** @return array{accepted: int, deduplicated: int, quarantined: int, results: list<mixed>} */
    private static function emptyBatch(): array
    {
        return ['accepted' => 0, 'deduplicated' => 0, 'quarantined' => 0, 'results' => []];
    }

    private static function deepCopy(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $copy = [];
        foreach ($value as $key => $entry) {
            $copy[$key] = self::deepCopy($entry);
        }
        return $copy;
    }

    /** @param array<string, string> $headers */
    private static function httpTransport(string $url, array $headers, string $body, int $timeoutMs): DataResponse
    {
        $lines = [];
        foreach ($headers as $key => $value) {
            $lines[] = $key.': '.$value;
        }
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $lines),
            'content' => $body,
            'timeout' => max(0.001, $timeoutMs / 1_000),
            'ignore_errors' => true,
        ]]);
        $responseBody = file_get_contents($url, false, $context);
        if ($responseBody === false) {
            throw new CustomyDataException('HTTP transport failed');
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                $status = (int) $matches[1];
                break;
            }
        }
        return new DataResponse($status, $responseBody);
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
}
