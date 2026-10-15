<?php

namespace App\Domain\Calls\Exceptions;

use RuntimeException;

/**
 * A provider could not start this call, for a reason a person can act on.
 *
 * Not an error to surface. The caller answers it by falling back to the rep
 * dialling by hand, which is a perfectly good way for the call to happen — the
 * reason is kept so the screen can say why the workspace's own telephony did
 * not ring.
 */
class CallCannotBePlaced extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message ?: $reason);
    }
}
