<?php

/**
 * Contract drift check: the contract facts hard-coded in this package must still match the
 * published contract.
 *
 * Why it has to exist: the enums and limits in an SDK are all constants copied from the contract.
 * When the contract changes and these do not, nothing raises an error - requests still go out,
 * responses still parse, it is only that some new option gets rejected locally as an illegal value.
 * This kind of failure emits no signal.
 *
 * Why it compares against the published contract rather than the spicy-server repository: this
 * repository is public and spicy-server is not. Giving a public repository's CI a token that can
 * read a private one puts it somewhere anyone can open a pull request against. The contract is
 * public anyway.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/SpicyClient.php';

const LIVE_URL = 'https://docs.spicyapi.ai/openapi.yaml';
const LOCAL = __DIR__ . '/../contracts/openapi.yaml';

/**
 * Retrieves the published contract.
 *
 * A User-Agent is mandatory: the docs site's edge protection answers 403 to requests without one,
 * while the api host does not (both were tested on 2026-09-20 and they behave differently).
 * Without that one header this check fails in the name of "contract drift" for a reason that has
 * nothing to do with the contract.
 */
function fetchLive(): string
{
    $context = stream_context_create([
        'http' => ['header' => "User-Agent: spicyapi-contract-check\r\n", 'timeout' => 30],
    ]);
    $body = @file_get_contents(LIVE_URL, false, $context);
    if ($body === false) {
        fwrite(STDERR, "[contract] the published contract is unreachable\n");
        exit(1);
    }
    return $body;
}

/** Returns the enum values of $field in the section following $anchor. */
function enumAfter(string $contract, string $anchor, string $field): array
{
    $start = strpos($contract, $anchor);
    if ($start === false) {
        fwrite(STDERR, "[contract] the contract has no {$anchor} schema\n");
        exit(1);
    }
    $window = substr($contract, $start, 4000);
    $at = strpos($window, $field);
    if ($at === false) {
        fwrite(STDERR, "[contract] {$anchor} has no {$field}\n");
        exit(1);
    }
    if (!preg_match('/enum:\s*\[([^\]]+)\]/', substr($window, $at, 600), $m)) {
        fwrite(STDERR, "[contract] {$anchor}{$field} has no enum\n");
        exit(1);
    }
    $values = array_map(
        static fn (string $v): string => trim(trim($v), "'\""),
        explode(',', $m[1]),
    );
    sort($values);
    return $values;
}

$live = fetchLive();
$local = is_file(LOCAL) ? file_get_contents(LOCAL) : '';

if ($local !== $live) {
    fwrite(STDERR,
        "[contract] contracts/openapi.yaml is stale against the published contract.\n" .
        "[contract] refresh it:  curl -sH 'User-Agent: spicyapi-contract-check' " . LIVE_URL . " -o contracts/openapi.yaml\n" .
        "[contract] then re-check every constant this package hard-codes against that diff.\n");
    exit(1);
}

// Upload types are a backed enum here and an enum list in the contract - both are complete sets,
// which is what makes a value-by-value comparison meaningful. The enum's cases() guarantees a newly
// added member cannot be missed.
$ours = array_map(
    static fn (\SpicyApi\UploadContentType $case): string => $case->value,
    \SpicyApi\UploadContentType::cases(),
);
sort($ours);

$theirs = enumAfter($live, '    UploadURLRequest:', 'contentType:');
if ($ours !== $theirs) {
    fwrite(STDERR, sprintf(
        "[contract] upload content types drifted:\n  package:  %s\n  contract: %s\n",
        implode(', ', $ours),
        implode(', ', $theirs),
    ));
    exit(1);
}

echo "[contract] matches the published contract.\n";
