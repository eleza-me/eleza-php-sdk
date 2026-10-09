<?php

/**
 * Sends a photo and a document to a chat.
 *
 *     php examples/04-media.php <chat id> photo.jpg report.pdf
 *
 * The chat id comes from an update ($ctx->chatId()): a bot can write only to
 * people who started it.
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Eleza\Bot\ChatAction;
use Eleza\Bot\Client;
use Eleza\Bot\Exception\ApiException;
use Eleza\Bot\InputFile;

[$chatId, $photo, $document] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''];
if ($chatId === '' || $photo === '') {
    fwrite(STDERR, "usage: php 04-media.php <chat id> <photo> [document]\n");
    exit(1);
}

$api = new Client(elezaToken());

try {
    $api->sendChatAction($chatId, ChatAction::UPLOADING_PHOTO);

    // Upload and send in one call…
    $sent = $api->sendPhoto($chatId, InputFile::fromPath($photo), ['text' => 'یک عکس', 'media_spoiler' => false]);
    echo "photo sent as message #{$sent['seq']}\n";

    if ($document !== '') {
        // …or upload once and reuse the media id for many chats.
        $mediaId = $api->uploadFile(InputFile::fromPath($document), 'file');
        $sent = $api->sendFile($chatId, $mediaId, ['text' => basename($document)]);
        echo "document sent as message #{$sent['seq']} (media {$mediaId})\n";
    }
} catch (ApiException $e) {
    if ($e->isCannotWrite()) {
        fwrite(STDERR, "This person has not started the bot, or has blocked it.\n");
        exit(1);
    }
    throw $e;
}
