<?php

declare(strict_types=1);

namespace Nordkit\Wiretap;

enum HttpDirection: string
{
    case Outbound = 'outbound';

    /**
     * Reserved for future inbound (incoming webhook) logging support.
     * Not currently used by any built-in driver or listener.
     *
     * @internal
     */
    case Inbound = 'inbound';
}
