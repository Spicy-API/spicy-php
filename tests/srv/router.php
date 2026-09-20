<?php

/**
 * The routing script for `php -S`: loopback.php's counterpart.
 *
 * It exists for the same reason the loopback suite does - unit.php sees what we believe we sent,
 * and only a real trip through a socket reveals which header PHP's transport quietly rewrote or
 * added. So this echoes back the method, path, headers and body exactly as the server received
 * them, with no normalisation whatsoever.
 *
 * State lives in a few files in this directory (flaky.count, received.bin,
 * received-headers.json), because the built-in server forks a process per request and an in-process
 * variable does not survive one.
 */

declare(strict_types=1);

$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$body = (string) file_get_contents('php://input');

/** The request headers as received, keys lower-cased - HTTP header names are case-insensitive and
 * an assertion should not trip over capitalisation. */
function received_headers(): array
{
    $out = [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $out[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
        }
    }
    foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $from => $to) {
        if (isset($_SERVER[$from]) && $_SERVER[$from] !== '') {
            $out[$to] = (string) $_SERVER[$from];
        }
    }
    return $out;
}

function envelope(mixed $data, int $status = 200, int $code = 200, string $msg = 'success', string $requestId = 'r'): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode([
        'code' => $code,
        'msg' => $msg,
        'request_id' => $requestId,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
}

// -- The object-storage hop: write the bytes and headers to disk verbatim, for the upload tests
if (str_starts_with($path, '/storage/')) {
    file_put_contents(__DIR__ . '/received.bin', $body);
    file_put_contents(__DIR__ . '/received-headers.json', json_encode(received_headers()));
    http_response_code(200);
    return;
}

// -- 503 first, success second: proves the retry really happens at the socket layer
if (str_ends_with($path, '/flaky')) {
    $counter = __DIR__ . '/flaky.count';
    $attempts = ((int) @file_get_contents($counter)) + 1;
    file_put_contents($counter, (string) $attempts);
    if ($attempts === 1) {
        header('Retry-After: 0');
        envelope(null, 503, 50301, 'upstream unavailable', 'r2');
        return;
    }
    envelope(['attempts' => $attempts]);
    return;
}

// -- An explicit business rejection: code, message and request_id must all reach the caller intact
if (str_ends_with($path, '/boom')) {
    envelope(null, 400, 40004, 'change aspect_ratio', 'r3');
    return;
}

// -- Everything else is echoed back
envelope([
    'method' => $method,
    'path' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
    'headers' => received_headers(),
    'body' => $body,
]);
