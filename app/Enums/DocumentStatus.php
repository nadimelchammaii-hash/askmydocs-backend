<?php

namespace App\Enums;

/**
 * Processing lifecycle of an uploaded document:
 *
 *   Queued -> Extracting -> Ready
 *                 \-> Failed (error_code / error_message say why)
 *
 * Phase 3 adds an embedding step between Extracting and Ready.
 */
enum DocumentStatus: string
{
    case Queued = 'queued';
    case Extracting = 'extracting';
    case Ready = 'ready';
    case Failed = 'failed';

    /** Whether processing has finished (successfully or not), so clients can stop polling. */
    public function isFinished(): bool
    {
        return $this === self::Ready || $this === self::Failed;
    }
}
