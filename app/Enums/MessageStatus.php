<?php

namespace App\Enums;

/**
 * A user's question is stored as completed. An assistant answer starts as
 * pending and becomes completed (answer or "not found") or failed.
 */
enum MessageStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';

    /** Whether the client can stop polling. */
    public function isFinished(): bool
    {
        return $this !== self::Pending;
    }
}
