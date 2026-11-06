<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Services\Answering\QuestionLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Numbers for the SPA dashboard, all about the current user.
 */
class StatsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        // One query: documents and pages per status.
        $byStatus = $user->documents()->toBase()
            ->selectRaw('status, count(*) as documents, coalesce(sum(page_count), 0) as pages')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $count = fn (DocumentStatus $status): int => (int) ($byStatus[$status->value]->documents ?? 0);
        $total = (int) $byStatus->sum('documents');
        $ready = $count(DocumentStatus::Ready);
        $failed = $count(DocumentStatus::Failed);

        $limit = QuestionLimits::perDay($user);
        $used = min(QuestionLimits::usedToday($user), $limit);

        return response()->json(['data' => [
            'documents' => [
                'total' => $total,
                'ready' => $ready,
                'processing' => $total - $ready - $failed,
                'failed' => $failed,
            ],
            'pages_indexed' => (int) ($byStatus[DocumentStatus::Ready->value]->pages ?? 0),
            'conversations' => $user->conversations()->count(),
            'questions_today' => [
                'used' => $used,
                'limit' => $limit,
                'remaining' => $limit - $used,
                'resets_at' => QuestionLimits::resetsAt()->toIso8601String(),
            ],
        ]]);
    }
}
