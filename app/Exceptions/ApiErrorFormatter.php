<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Gives every API error the same JSON shape: {message, code, errors?}.
 *
 * Runs after Laravel has rendered the exception, so the framework still decides
 * the status code and headers (e.g. Retry-After on 429). This class only
 * normalises the body and removes anything that could leak internals: debug
 * traces, model class names in 404s and messages of server errors. Server
 * errors are still logged in full by Laravel's exception reporting.
 */
class ApiErrorFormatter
{
    /** @var array<int, string> */
    private const CODES = [
        400 => 'bad_request',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        413 => 'payload_too_large',
        419 => 'csrf_token_mismatch',
        422 => 'validation_failed',
        429 => 'too_many_requests',
    ];

    public function __invoke(Response $response, Throwable $exception, Request $request): Response
    {
        if (! $request->is('api/*') || ! $response instanceof JsonResponse || $response->getStatusCode() < 400) {
            return $response;
        }

        $status = $response->getStatusCode();
        $data = (array) $response->getData(true);

        $payload = [
            'message' => $this->message($status, $data),
            'code' => $data['code'] ?? self::CODES[$status] ?? ($status >= 500 ? 'server_error' : 'error'),
        ];

        if (isset($data['errors'])) {
            $payload['errors'] = $data['errors'];
        }

        return $response->setData($payload);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function message(int $status, array $data): string
    {
        return match (true) {
            $status >= 500 => 'Server error.',
            $status === 404 => 'Resource not found.',
            default => (string) ($data['message'] ?? 'Request failed.'),
        };
    }
}
