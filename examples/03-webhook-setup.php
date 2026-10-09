<?php

/**
 * Registers (or removes) the webhook. Run once from the command line:
 *
 *     php examples/03-webhook-setup.php https://bot.example.com/eleza/hook.php
 *     php examples/03-webhook-setup.php --info
 *     php examples/03-webhook-setup.php --delete
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Eleza\Bot\Client;

$api = new Client(elezaToken());
$arg = $argv[1] ?? '--info';

if ($arg === '--delete') {
    $api->deleteWebhook();
    echo "Webhook removed; the bot is back on long polling.\n";
} elseif ($arg === '--info') {
    echo json_encode($api->getWebhookInfo(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
} else {
    $info = $api->setWebhook($arg, ['max_connections' => 4]);
    echo "Webhook set to {$info['url']}\n";
    if (isset($info['secret'])) {
        // Shown only now. Keep it where the endpoint can read it.
        echo "ELEZA_WEBHOOK_SECRET={$info['secret']}\n";
    }
}
