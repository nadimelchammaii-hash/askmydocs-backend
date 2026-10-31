<?php

namespace App\Services\OpenAi;

use RuntimeException;

/**
 * Why a call to OpenAI failed. `reason` is for logs, tests and deciding what to
 * show; the message is safe to log but is NOT shown to users as is.
 *
 * Neither the message nor `detail` ever contains the API key, the prompt or
 * document text.
 *
 * Permanent: retrying the same request cannot help (bad key, no credit,
 * rejected or unusable request/response). Transient: may succeed later
 * (rate limit, provider outage, network).
 */
final class OpenAiException extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';

    public const AUTHENTICATION_FAILED = 'authentication_failed';

    public const QUOTA_EXCEEDED = 'quota_exceeded';

    public const REQUEST_REJECTED = 'request_rejected';

    public const INVALID_RESPONSE = 'invalid_response';

    /** The model stopped before finishing (max_output_tokens reached). */
    public const OUTPUT_TRUNCATED = 'output_truncated';

    /** The model declined to answer, or OpenAI's content filter stopped it. */
    public const REFUSED = 'refused';

    public const RATE_LIMITED = 'rate_limited';

    public const PROVIDER_ERROR = 'provider_error';

    public const CONNECTION_FAILED = 'connection_failed';

    private function __construct(
        public readonly string $reason,
        string $message,
        private readonly bool $permanent,
        /** Status code and provider error type/code, for logs. */
        public readonly ?string $detail = null,
        /** Seconds the provider asked us to wait (Retry-After), if any. */
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self(self::NOT_CONFIGURED, 'No OpenAI API key is configured (OPENAI_API_KEY).', true);
    }

    public static function authenticationFailed(string $detail): self
    {
        return new self(self::AUTHENTICATION_FAILED, 'OpenAI rejected the API key.', true, $detail);
    }

    public static function quotaExceeded(string $detail): self
    {
        return new self(self::QUOTA_EXCEEDED, 'The OpenAI account has no remaining quota or credit.', true, $detail);
    }

    public static function requestRejected(string $detail): self
    {
        return new self(self::REQUEST_REJECTED, 'OpenAI rejected the request.', true, $detail);
    }

    public static function invalidResponse(string $detail): self
    {
        return new self(self::INVALID_RESPONSE, 'OpenAI returned an unexpected response.', true, $detail);
    }

    public static function outputTruncated(string $detail): self
    {
        return new self(self::OUTPUT_TRUNCATED, 'The model reached its output limit before finishing.', true, $detail);
    }

    public static function refused(string $detail): self
    {
        return new self(self::REFUSED, 'The model declined to respond.', true, $detail);
    }

    public static function rateLimited(string $detail, ?int $retryAfter): self
    {
        return new self(self::RATE_LIMITED, 'OpenAI rate limit reached.', false, $detail, $retryAfter);
    }

    public static function providerError(string $detail): self
    {
        return new self(self::PROVIDER_ERROR, 'OpenAI failed to process the request.', false, $detail);
    }

    public static function connectionFailed(string $detail): self
    {
        return new self(self::CONNECTION_FAILED, 'Could not reach OpenAI.', false, $detail);
    }

    public function isPermanent(): bool
    {
        return $this->permanent;
    }
}
