<?php

declare(strict_types=1);

namespace Eleza\Bot\Exception;

/** A webhook delivery that is not signed by Eleza, is too old, or is not an update. */
class WebhookException extends ElezaException
{
}
