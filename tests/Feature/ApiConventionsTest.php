<?php

use Illuminate\Support\Facades\Route;

it('returns 401 JSON even when the client does not ask for JSON', function () {
    // Without the redirectGuestsTo override this would be a 500 ("Route [login] not defined").
    $this->get('/api/v1/user')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.', 'code' => 'unauthenticated']);
});

it('returns a generic 404 for unknown endpoints', function () {
    $this->getJson('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertExactJson(['message' => 'Resource not found.', 'code' => 'not_found']);
});

it('includes field errors in validation responses', function () {
    $this->fromSpa()->postJson('/api/v1/auth/login', [])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['message', 'code', 'errors' => ['email', 'password']]);
});

it('hides internal details of server errors', function () {
    config(['app.debug' => true]);
    Route::get('/api/v1/test-server-error', fn () => throw new RuntimeException('secret internal detail'));

    $response = $this->getJson('/api/v1/test-server-error');

    $response->assertInternalServerError()
        ->assertExactJson(['message' => 'Server error.', 'code' => 'server_error']);
    expect($response->getContent())->not->toContain('secret internal detail');
});

it('allows CORS with credentials from the frontend origin only', function () {
    $frontend = config('app.frontend_url');

    $this->withHeaders([
        'Origin' => $frontend,
        'Access-Control-Request-Method' => 'POST',
    ])->options('/api/v1/auth/login')
        ->assertHeader('Access-Control-Allow-Origin', $frontend)
        ->assertHeader('Access-Control-Allow-Credentials', 'true');

    // With a single allowed origin the header is static, so a foreign site receives
    // the frontend's origin, which its browser rejects. It must never be echoed back.
    $foreign = $this->withHeaders([
        'Origin' => 'https://evil.example',
        'Access-Control-Request-Method' => 'POST',
    ])->options('/api/v1/auth/login');

    expect($foreign->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://evil.example');
});

it('issues the CSRF cookie the SPA needs before logging in', function () {
    $this->fromSpa()->get('/sanctum/csrf-cookie')
        ->assertNoContent()
        ->assertCookie('XSRF-TOKEN');
});
