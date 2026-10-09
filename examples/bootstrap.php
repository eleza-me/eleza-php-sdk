<?php

/**
 * Shared by the examples: loads the SDK and reads the token from the
 * environment, so it never ends up in a file.
 *
 *     ELEZA_BOT_TOKEN='ezb1.…' php examples/01-echo.php
 */

declare(strict_types=1);

require is_file(__DIR__ . '/../vendor/autoload.php') ? __DIR__ . '/../vendor/autoload.php' : __DIR__ . '/../autoload.php';

function elezaToken(): string
{
    $token = getenv('ELEZA_BOT_TOKEN');
    if (!is_string($token) || $token === '') {
        fwrite(STDERR, "Set ELEZA_BOT_TOKEN to the token @BotFather gave you.\n");
        exit(1);
    }

    return $token;
}
