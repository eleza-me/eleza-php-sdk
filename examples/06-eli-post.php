<?php

/**
 * Publishes on Eli as the bot and listens to what people do with the post.
 *
 *     php examples/06-eli-post.php "متن پست" [image.jpg]
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Eleza\Bot\Bot;
use Eleza\Bot\Context;
use Eleza\Bot\InputFile;
use Eleza\Bot\UpdateType;

$text = $argv[1] ?? 'سلام الی! #eleza';
$image = $argv[2] ?? null;

$bot = new Bot(elezaToken());

$post = ['text' => $text];
if ($image !== null) {
    // Media of a post is uploaded with purpose "post".
    $post['media'] = [['id' => $bot->api->uploadFile(InputFile::fromPath($image), 'image', 'post'), 'alt' => $text]];
}
$created = $bot->api->createPost($post);
echo "published post {$created['id']}" . (($created['limited'] ?? false) ? " (followers and profile only: the bot is not official)\n" : "\n");

$bot->on(UpdateType::POST_REPLY_CREATED, function (Context $ctx): void {
    echo ($ctx->fromName() ?: $ctx->fromId()) . ' replied: ' . ($ctx->update->reply()['text'] ?? '') . "\n";
});
$bot->on(UpdateType::POST_REACTION_CHANGED, function (Context $ctx): void {
    echo "reaction on {$ctx->update->postId()}: {$ctx->update->previousReaction()} → {$ctx->update->reaction()}\n";
});
$bot->on(UpdateType::FOLLOWER_ADDED, function (Context $ctx): void {
    echo 'new follower: ' . ($ctx->fromName() ?: $ctx->fromId()) . "\n";
});

echo "Listening for replies, reactions and followers — Ctrl+C to stop\n";
$bot->run();
