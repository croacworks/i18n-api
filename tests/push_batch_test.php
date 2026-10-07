<?php

declare(strict_types=1);

$baseUrl = rtrim(getenv('I18N_TEST_BASE_URL') ?: 'http://127.0.0.1', '/');
$token = getenv('I18N_TEST_TOKEN') ?: 'test-token';
$prefix = 'batch-test-' . bin2hex(random_bytes(4));

function request(string $method, string $url, string $token, ?array $payload = null): array
{
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
    ];
    $content = null;
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $content = json_encode($payload, JSON_THROW_ON_ERROR);
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $content,
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    $body = file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
            $status = (int) $matches[1];
            break;
        }
    }

    return [
        'status' => $status,
        'body' => json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR),
    ];
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . '.');
    }
}

function assertTrueValue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$single = request('POST', $baseUrl . '/api/push', $token, [
    'category' => 'test',
    'message' => $prefix . '-single',
    'translations' => ['pt-BR' => 'Individual'],
    'overwrite' => true,
]);
assertSameValue(200, $single['status'], 'The legacy single-item request must remain supported.');
assertTrueValue(isset($single['body']['id']), 'The legacy response must keep its id.');

$batch = request('POST', $baseUrl . '/api/push', $token, [[
    'category' => 'test',
    'message' => $prefix . '-first',
    'translations' => ['pt_BR' => 'Primeiro'],
    'overwrite' => true,
], [
    'category' => 'test',
    'message' => $prefix . '-second',
    'translations' => ['pt-BR' => 'Segundo'],
    'overwrite' => true,
]]);
assertSameValue(200, $batch['status'], 'A raw batch must be accepted.');
assertSameValue(2, $batch['body']['processed'] ?? null, 'Both batch items must be processed.');
assertSameValue(2, $batch['body']['created'] ?? null, 'Both new source messages must be reported.');

$wrapped = request('POST', $baseUrl . '/api/push', $token, ['items' => [[
    'category' => 'test',
    'message' => $prefix . '-wrapped',
    'translations' => [],
    'overwrite' => true,
]]]);
assertSameValue(200, $wrapped['status'], 'The items envelope must be accepted.');
assertSameValue(1, $wrapped['body']['processed'] ?? null, 'The wrapped item must be processed.');

$oversizedBatch = [];
for ($index = 0; $index < 1001; $index++) {
    $oversizedBatch[] = [
        'category' => 'test',
        'message' => $prefix . '-limit-' . $index,
    ];
}
$tooMany = request('POST', $baseUrl . '/api/push', $token, $oversizedBatch);
assertSameValue(413, $tooMany['status'], 'A batch larger than 1000 items must be rejected.');

$invalid = request('POST', $baseUrl . '/api/push', $token, [[
    'category' => 'test',
    'message' => $prefix . '-must-not-exist',
    'translations' => ['pt-BR' => 'Rollback'],
    'overwrite' => true,
], [
    'category' => '',
    'message' => 'invalid',
]]);
assertSameValue(400, $invalid['status'], 'An invalid item must reject the complete batch.');

$invalidTypes = request('POST', $baseUrl . '/api/push', $token, [[
    'category' => ['not-a-string'],
    'message' => $prefix . '-invalid-type',
]]);
assertSameValue(400, $invalidTypes['status'], 'Non-scalar source fields must be rejected cleanly.');

$conflictSeed = request('POST', $baseUrl . '/api/push', $token, [
    'category' => 'test',
    'message' => $prefix . '-conflict',
    'translations' => ['pt-BR' => 'Original'],
    'overwrite' => true,
]);
assertSameValue(200, $conflictSeed['status'], 'The conflict fixture must be created.');
$conflict = request('POST', $baseUrl . '/api/push', $token, [[
    'category' => 'test',
    'message' => $prefix . '-rolled-back',
    'translations' => ['pt-BR' => 'Rollback'],
    'overwrite' => true,
], [
    'category' => 'test',
    'message' => $prefix . '-conflict',
    'translations' => ['pt-BR' => 'Must fail'],
]]);
assertSameValue(409, $conflict['status'], 'A translation conflict must return HTTP 409.');

$pull = request('GET', $baseUrl . '/api/pull', $token);
assertSameValue(200, $pull['status'], 'Pull must remain available.');
$messages = array_column($pull['body'], 'message');
assertTrueValue(!in_array($prefix . '-must-not-exist', $messages, true), 'Validation must happen before writes.');
assertTrueValue(!in_array($prefix . '-rolled-back', $messages, true), 'A conflict must roll back earlier items.');

$unauthorized = request('POST', $baseUrl . '/api/push', 'wrong-token', [[
    'category' => 'test',
    'message' => $prefix . '-unauthorized',
]]);
assertSameValue(401, $unauthorized['status'], 'Batch writes must remain authenticated.');

echo "Batch push API tests passed.\n";
