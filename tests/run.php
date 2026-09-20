<?php

/**
 * The test entry point: this is what `composer test` runs.
 *
 * The split into two suites is deliberate, because they prove different things:
 *
 *   unit.php     -- injects a fake transport and asserts the shape of what we send (headers, body,
 *                   what is and is not retried, business-code guidance). Fast, and never touches
 *                   the network.
 *   loopback.php -- starts a real PHP built-in server so requests genuinely cross a socket. It
 *                   catches what unit.php cannot: a header quietly rewritten or added by PHP's
 *                   transport layer, where a fake transport only ever shows what we believed we
 *                   sent.
 */

declare(strict_types=1);

$failed = 0;

foreach (['unit.php', 'loopback.php'] as $suite) {
    fwrite(STDERR, "\n=== {$suite} ===\n");
    $code = 0;
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $suite), $code);
    if ($code !== 0) {
        $failed++;
    }
}

exit($failed === 0 ? 0 : 1);
