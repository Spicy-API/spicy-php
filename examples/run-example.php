<?php

/**
 * Minimal end-to-end run against a real model.
 *
 * Usage:
 *   export SPICY_API_KEY="sk-spicy-..."
 *   php run-example.php <model-id> "<prompt>" [path/to/reference-image.png]
 *
 * Pick <model-id> from the live catalogue, never from a hard-coded list:
 *
 *   php -r 'require "SpicyClient.php";
 *     foreach ((new SpicyApi\SpicyClient())->listModels(modality: "image")["items"] as $m) {
 *         echo $m["model"], PHP_EOL;
 *     }'
 *
 * The optional third argument demonstrates the three-step upload: the file is
 * PUT straight to storage, and the committed `spicy://f/fil_...` URI — not a
 * local path — is what goes into the model input.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/SpicyClient.php';

use SpicyApi\SpicyApiError;
use SpicyApi\SpicyClient;
use SpicyApi\SpicyTimeoutError;
use SpicyApi\TaskErrorCode;
use SpicyApi\TaskState;

$model = $argv[1] ?? '';
$prompt = $argv[2] ?? '';
$imagePath = $argv[3] ?? null;
if ($model === '' || $prompt === '') {
    fwrite(STDERR, "Usage: php run-example.php <model-id> \"<prompt>\" [reference-image]\n");
    exit(2);
}

try {
    $client = new SpicyClient();

    // Read the schema first: field names and accepted values come from it, not from an example
    // copied elsewhere.
    $schema = $client->getModel($model);
    printf("model: %s\n", $schema['model'] ?? $model);

    $input = ['prompt' => $prompt];
    if ($imagePath !== null) {
        $file = $client->uploadFile($imagePath);
        printf("uploaded: %s (%s, %d bytes)\n", $file['uri'], $file['contentType'], $file['bytes']);
        // The field name comes from this model's inputSchema; `image_url` is the most common one
        // in the catalogue.
        $input['image_url'] = $file['uri'];
    }

    // Quote first, then create the task against that same quote: a changed price is stopped with
    // a 40901 before anything is charged, rather than quietly settling at the new one.
    $quote = $client->quote($model, $input);
    printf("quote: %s USD (max %s, expires %s)\n", $quote['estimatedCost'], $quote['maxCharge'], $quote['expiresAt']);

    $accepted = $client->createTask(
        model: $model,
        input: $input,
        // In a real system, derive this from a stable identifier you already have (an order
        // number, a row id) rather than randomising each time: a fresh key on retry is the same as
        // having no idempotency at all.
        idempotencyKey: SpicyClient::idempotencyKey(),
        quoteId: $quote['quoteId'],
        expectedCost: $quote['estimatedCost'],
    );
    printf("task: %s (held %s USD)\n", $accepted['taskId'], $accepted['estimatedCost']);

    $task = $client->waitForTerminal(
        $accepted['taskId'],
        onUpdate: static function (array $update): void {
            printf("  ... %s\n", $update['state'] ?? '?');
        },
    );

    $state = TaskState::tryFrom((string) ($task['state'] ?? ''));
    printf("state: %s, charged %s USD (settled: %s)\n",
        $task['state'] ?? '?', $task['cost'] ?? '?', ($task['settled'] ?? false) ? 'yes' : 'no');

    if ($state !== TaskState::Succeeded) {
        $code = TaskErrorCode::fromTask($task);
        printf("failed: %s — %s\n", $code?->value ?? 'unknown', $task['errorMessage'] ?? '');
        // Always branch on errorCode, never errorMessage: the latter is translated and may carry
        // an upstream message verbatim.
        printf("worth retrying: %s\n", ($code?->isWorthRetrying() ?? false) ? 'yes' : 'no');
        exit(1);
    }

    // The output is not always a file: some endpoints answer in output.text and carry no assets
    // key at all.
    $text = SpicyClient::outputText($task);
    if ($text !== null) {
        printf("text output:\n%s\n", $text);
    }
    foreach (SpicyClient::readyAssets($task) as $index => $asset) {
        printf("asset %d: %s (%s, expires %s)\n",
            $index, $asset['url'], $asset['mime'] ?? '?', $asset['expiresAt'] ?? '?');
    }
    if ($text === null && SpicyClient::readyAssets($task) === []) {
        print("the task succeeded but produced no ready output\n");
    }
    exit(0);
} catch (SpicyTimeoutError $error) {
    // A local wait timing out does not mean the generation failed: the task is most likely still
    // running, and still being billed.
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
} catch (SpicyApiError $error) {
    fwrite(STDERR, $error->describe() . "\n" . $error->guidance() . "\n");
    exit(1);
}
