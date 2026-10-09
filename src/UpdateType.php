<?php

declare(strict_types=1);

namespace Eleza\Bot;

/**
 * The "type" of an update. New types may appear at any time: ignore the ones
 * you do not know.
 */
final class UpdateType
{
    /** The person pressed Start or sent /start (with an optional deep-link payload). */
    public const USER_STARTED_BOT = 'user.started_bot';
    public const MESSAGE_CREATED = 'message.created';
    public const MESSAGE_EDITED = 'message.edited';
    public const MESSAGE_DELETED = 'message.deleted';
    /** A message that starts with /command (other than /start). */
    public const COMMAND_RECEIVED = 'command.received';
    /** A press on an inline button that carries callback_data. */
    public const CALLBACK_QUERY = 'callback_query';
    public const INLINE_QUERY = 'inline_query';
    public const REACTION_CREATED = 'reaction.created';
    public const REACTION_REMOVED = 'reaction.removed';
    public const BOT_BLOCKED = 'bot.blocked';
    public const BOT_UNBLOCKED = 'bot.unblocked';
    /** Someone replied to one of the bot's Eli posts. */
    public const POST_REPLY_CREATED = 'post.reply_created';
    /** A like or dislike on one of the bot's Eli posts changed (never says who). */
    public const POST_REACTION_CHANGED = 'post.reaction_changed';
    public const FOLLOWER_ADDED = 'follower.added';

    public const ALL = [
        self::USER_STARTED_BOT, self::MESSAGE_CREATED, self::MESSAGE_EDITED, self::MESSAGE_DELETED, self::COMMAND_RECEIVED,
        self::CALLBACK_QUERY, self::INLINE_QUERY, self::REACTION_CREATED, self::REACTION_REMOVED, self::BOT_BLOCKED,
        self::BOT_UNBLOCKED, self::POST_REPLY_CREATED, self::POST_REACTION_CHANGED, self::FOLLOWER_ADDED,
    ];
}
