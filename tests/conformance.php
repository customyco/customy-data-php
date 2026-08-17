<?php

declare(strict_types=1);

require_once __DIR__.'/../src/CustomyDataException.php';
require_once __DIR__.'/../src/DataResponse.php';
require_once __DIR__.'/../src/CustomyDataClient.php';

use Customy\Data\CustomyDataClient;
use Customy\Data\CustomyDataException;
use Customy\Data\DataResponse;

/** @param list<int> $statuses @param list<array<string, mixed>> $bodies */
function recorder(array &$statuses, array &$bodies): callable
{
    return static function (string $url, array $headers, string $body, int $timeoutMs) use (&$statuses, &$bodies): DataResponse {
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $bodies[] = $payload;
        $status = array_shift($statuses) ?? 202;
        $count = isset($payload['batch']) ? count($payload['batch']) : 1;
        $response = $status < 300
            ? ['accepted' => $count, 'deduplicated' => 0, 'quarantined' => 0, 'results' => []]
            : ['error' => 'temporary'];
        return new DataResponse($status, json_encode($response, JSON_THROW_ON_ERROR));
    };
}

/** @param list<int> $statuses @param list<array<string, mixed>> $bodies @param list<string> $redact */
function client(array &$statuses, array &$bodies, int $retries = 3, int $batch = 100, array $redact = [], ?callable $hook = null): CustomyDataClient
{
    $id = 0;
    return new CustomyDataClient(
        'https://data.customy.ai',
        'cdw_test',
        recorder($statuses, $bodies),
        $retries,
        0,
        maxBatchSize: $batch,
        redactFields: $redact,
        beforeSend: $hook,
        now: static fn (): DateTimeImmutable => new DateTimeImmutable('2026-08-16T00:00:00Z'),
        idFactory: static function () use (&$id): string { return 'message_'.++$id; },
    );
}

function check(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('failed: '.$label);
    }
}

$conformancePath = getenv('CUSTOMY_DATA_CONFORMANCE_PATH') ?: __DIR__.'/../../sdk-data/conformance/customer-data-v1.json';
$vectors = json_decode(file_get_contents($conformancePath), true, 512, JSON_THROW_ON_ERROR);
check($vectors['contract'] === CustomyDataClient::CONFORMANCE_CONTRACT, 'contract');
$statuses = $bodies = [];
$sdk = client($statuses, $bodies);
foreach ($vectors['eventTypes'] as $event) {
    $sdk->sendEvent($event);
}
check(array_column($bodies, 'type') === ['track', 'identify', 'group', 'page', 'screen', 'alias'], 'six calls');
check(count(array_filter($bodies, static fn (array $event): bool => $event['schemaVersion'] === '1.0')) === 6, 'schema');

$statuses = [503, 429, 202]; $bodies = [];
client($statuses, $bodies)->track('Checkout Started', ['value' => 10], ['anonymousId' => 'anon_1']);
check(count(array_unique(array_column($bodies, 'messageId'))) === 1, 'stable retry');

$statuses = []; $bodies = [];
$redacting = client($statuses, $bodies, redact: ['password'], hook: static function (array $event): array {
    $event['traits'] = ['password' => 'reintroduced']; return $event;
});
$redacting->identify(['password' => 'secret'], ['userId' => 'u1']);
check($bodies[0]['traits']['password'] === '[REDACTED]', 'redaction');
try {
    $redacting->event(['type' => 'identify', 'userId' => 'u1', 'organizationId' => 'forged']);
    throw new RuntimeException('expected tenant rejection');
} catch (InvalidArgumentException) {
}

$statuses = [202, 503]; $bodies = [];
$partial = client($statuses, $bodies, 0, 2);
foreach (['A', 'B', 'C'] as $name) {
    $partial->enqueue(['type' => 'track', 'event' => $name, 'anonymousId' => 'anon_1']);
}
try {
    $partial->flush();
    throw new RuntimeException('expected partial failure');
} catch (CustomyDataException) {
}
check($partial->enqueue(['type' => 'track', 'event' => 'D', 'anonymousId' => 'anon_1']) === 4, 'queue restore');
echo "customy-data-php conformance passed\n";
