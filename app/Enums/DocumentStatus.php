<?php

namespace App\Enums;

/**
 * Processing lifecycle of an uploaded document:
 *
 *   Queued -> Extracting -> Embedding -> Ready
 *                 \------------\-> Failed (error_code / error_message say why)
 */
enum DocumentStatus: string
{
    case Queued = 'queued';
    case Extracting = 'extracting';
    case Embedding = 'embedding';
    case Ready = 'ready';
    case Failed = 'failed';

    /** Whether processing has finished (successfully or not), so clients can stop polling. */
    public function isFinished(): bool
    {
        return $this === self::Ready || $this === self::Failed;
    }
}
