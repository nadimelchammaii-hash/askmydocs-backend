<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Chunking\TextChunker;
use App\Services\Embeddings\EmbeddingClient;
use App\Services\Embeddings\OpenAiEmbeddingClient;
use App\Services\OpenAi\OpenAiHttp;
use App\Services\OpenAi\OpenAiResponsesClient;
use App\Services\OpenAi\StructuredOutputClient;
use App\Services\Pdf\PdfTextExtractor;
use App\Services\Pdf\PopplerPdfTextExtractor;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PdfTextExtractor::class, fn (): PdfTextExtractor => new PopplerPdfTextExtractor(
            pdftotextBinary: config('documents.pdftotext_binary'),
            pdfinfoBinary: config('documents.pdfinfo_binary'),
            timeoutSeconds: config('documents.process_timeout'),
        ));

        $this->app->bind(TextChunker::class, fn (): TextChunker => new TextChunker(
            targetChars: config('documents.chunking.target_chars'),
            maxChars: config('documents.chunking.max_chars'),
            overlapChars: config('documents.chunking.overlap_chars'),
        ));

        $this->app->bind(OpenAiHttp::class, fn (): OpenAiHttp => new OpenAiHttp(
            apiKey: config('services.openai.api_key'),
            baseUrl: config('services.openai.base_url'),
        ));

        $this->app->bind(EmbeddingClient::class, fn (): EmbeddingClient => new OpenAiEmbeddingClient(
            http: $this->app->make(OpenAiHttp::class),
            model: config('services.openai.embedding_model'),
            dimensions: config('services.openai.embedding_dimensions'),
            timeoutSeconds: config('services.openai.timeout'),
        ));

        $this->app->bind(StructuredOutputClient::class, fn (): StructuredOutputClient => new OpenAiResponsesClient(
            http: $this->app->make(OpenAiHttp::class),
            model: config('services.openai.answer_model'),
            reasoningEffort: config('services.openai.answer_reasoning_effort'),
            maxOutputTokens: config('services.openai.answer_max_output_tokens'),
            timeoutSeconds: config('services.openai.answer_timeout'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurePasswordRules();
        $this->configureRateLimiting();
        $this->configurePasswordResetLinks();
    }

    /**
     * Password rules used by registration, password reset and password change.
     *
     * In production, passwords are also checked against known data breaches
     * (Have I Been Pwned, k-anonymity: only the first 5 characters of the
     * password's SHA-1 hash leave the server). Skipped elsewhere so local
     * development and tests make no external calls.
     */
    private function configurePasswordRules(): void
    {
        Password::defaults(function (): Password {
            $rule = Password::min(8)->letters()->mixedCase()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });
    }

    /**
     * Login attempts are limited per email address and IP, so one attacker cannot
     * lock out a user from everywhere, and one IP cannot try many passwords.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip());
        });

        // Every question can cost an OpenAI call: limit bursts and daily volume per user.
        RateLimiter::for('questions', function (Request $request): array {
            $key = (string) $request->user()?->id ?: $request->ip();

            return [
                Limit::perMinute(config('answers.questions_per_minute'))->by($key),
                Limit::perDay(config('answers.questions_per_day'))->by($key)->response(fn () => response()->json([
                    'message' => 'You have reached today\x27s question limit. Please try again tomorrow.',
                    'code' => 'daily_limit_reached',
                ], 429)),
            ];
        });

        // Each upload stores up to 20 MB and runs pdfinfo, so uploads are limited per user.
        RateLimiter::for('uploads', function (Request $request): Limit {
            return Limit::perMinute(10)->by((string) $request->user()?->id ?: $request->ip());
        });
    }

    /**
     * Password reset emails link to the SPA, which posts the token back to the API.
     */
    private function configurePasswordResetLinks(): void
    {
        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            return config('app.frontend_url').'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]);
        });
    }
}
