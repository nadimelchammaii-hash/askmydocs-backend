<?php

namespace App\Services\Answers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Question limits (every question can cost an OpenAI call): a short burst limit and
 * a daily limit per user. The daily limit counts per calendar day (app timezone, UTC),
 * so "today" means the same thing for the limit and for the dashboard.
 *
 * The keys are explicit (not left to Laravel's automatic fallback keys) so that
 * usedToday() can read the daily counter.
 */
final class QuestionLimits
{
    /** Name of the rate limiter used by the question routes (throttle:questions). */
    public const LIMITER = 'questions';

    /**
     * @return list<Limit>
     */
    public static function for(string $requester): array
    {
        return [
            Limit::perMinute(config('answers.questions_per_minute'))->by('minute:'.$requester),
            Limit::perDay(config('answers.questions_per_day'))->by(self::dayKey($requester))->response(fn () => response()->json([
                'message' => 'You have reached today\'s question limit. Please try again tomorrow.',
                'code' => 'daily_limit_reached',
            ], 429)),
        ];
    }

    /** Questions the user has asked today (requests counted by the daily limit). */
    public static function usedToday(User $user): int
    {
        // The throttle middleware stores named limits under md5(limiter name . key);
        // StatsTest checks that this still matches the requests it counts.
        return RateLimiter::attempts(md5(self::LIMITER.self::dayKey((string) $user->id)));
    }

    public static function perDay(): int
    {
        return (int) config('answers.questions_per_day');
    }

    /** When today's count starts again from zero. */
    public static function resetsAt(): CarbonImmutable
    {
        return CarbonImmutable::now()->addDay()->startOfDay();
    }

    private static function dayKey(string $requester): string
    {
        return 'day:'.$requester.':'.CarbonImmutable::now()->toDateString();
    }
}
