<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * Behind a shared reverse proxy (compose.shared.yaml) every request arrives from the proxy's
 * address. Laravel must take the visitor's IP address and HTTPS from the proxy's headers, and
 * only from that proxy.
 */

const PROXY_RANGE = '10.250.250.0/24';
const PROXY_ADDRESS = '10.250.250.2';

beforeEach(function () {
    // Shows what Laravel concluded about the request.
    Route::get('/_test/request-info', fn (Request $request) => [
        'ip' => $request->ip(),
        'secure' => $request->secure(),
        'host' => $request->getHost(),
    ]);
});

function requestInfo(string $remoteAddress, array $headers = []): array
{
    return test()->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
        ->getJson('/_test/request-info', $headers)
        ->assertOk()
        ->json();
}

it('takes the visitor address and HTTPS from a trusted proxy', function () {
    config(['trustedproxy.proxies' => PROXY_RANGE]);

    $info = requestInfo(PROXY_ADDRESS, [
        'X-Forwarded-For' => '203.0.113.7',
        'X-Forwarded-Proto' => 'https',
    ]);

    expect($info)->toMatchArray(['ip' => '203.0.113.7', 'secure' => true]);
});

it('ignores a visitor address forged in front of the proxy', function () {
    config(['trustedproxy.proxies' => PROXY_RANGE]);

    // The visitor sent "X-Forwarded-For: 6.6.6.6"; the proxy appended the real address.
    $info = requestInfo(PROXY_ADDRESS, ['X-Forwarded-For' => '6.6.6.6, 203.0.113.7']);

    expect($info['ip'])->toBe('203.0.113.7');
});

it('ignores forwarded headers from addresses that are not trusted', function () {
    config(['trustedproxy.proxies' => PROXY_RANGE]);

    $info = requestInfo('198.51.100.9', [
        'X-Forwarded-For' => '203.0.113.7',
        'X-Forwarded-Proto' => 'https',
    ]);

    expect($info)->toMatchArray(['ip' => '198.51.100.9', 'secure' => false]);
});

it('trusts no proxy unless one is configured', function () {
    config(['trustedproxy.proxies' => null]);

    $info = requestInfo(PROXY_ADDRESS, ['X-Forwarded-For' => '203.0.113.7']);

    expect($info['ip'])->toBe(PROXY_ADDRESS);
});

it('never takes the host from a proxy header', function () {
    config(['trustedproxy.proxies' => PROXY_RANGE]);

    $info = requestInfo(PROXY_ADDRESS, ['X-Forwarded-Host' => 'evil.example']);

    expect($info['host'])->toBe('localhost');
});

it('gives each visitor behind the proxy their own rate limit', function () {
    config(['trustedproxy.proxies' => PROXY_RANGE, 'app.api_requests_per_minute' => 2]);

    $login = fn (string $visitor) => $this->withServerVariables(['REMOTE_ADDR' => PROXY_ADDRESS])
        ->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong'], [
            'X-Forwarded-For' => $visitor,
        ]);

    $login('203.0.113.7')->assertUnprocessable();
    $login('203.0.113.7')->assertUnprocessable();
    $login('203.0.113.7')->assertTooManyRequests();

    // Another visitor through the same proxy is not affected.
    $login('203.0.113.8')->assertUnprocessable();
});
