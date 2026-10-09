<?php

declare(strict_types=1);

namespace Eleza\Bot\Exception;

/** The request did not get an HTTP answer: DNS, connection, TLS or timeout. */
class TransportException extends ElezaException
{
}
