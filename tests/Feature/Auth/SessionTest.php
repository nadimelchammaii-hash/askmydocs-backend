<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;

it('logs in with valid credentials', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);

    $this->fromSpa()->postJson('/api/v1/auth/login', [
        'email' => 'Ana@Example.com',
        'password' => 'password',
    ])->assertOk()->assertJsonPath('data.id', $user->id);

    $this->assertAuthenticatedAs($user, 'web');
});

it('rejects a wrong password with the same error as an unknown email', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    $wrongPassword = $this->fromSpa()->postJson('/api/v1/auth/login', [
        'email' => 'ana@example.com',
        'password' => 'not-the-password',
    ])->assertUnprocessable();

    $unknownEmail = $this->fromSpa()->postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'not-the-password',
    ])->assertUnprocessable();

    expect($wrongPassword->json('errors.email'))->toBe($unknownEmail->json('errors.email'));
    $this->assertGuest('web');
});

it('throttles login after five attempts per email and IP', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    foreach (range(1, 5) as $attempt) {
        $this->fromSpa()->postJson('/api/v1/auth/login', [
            'email' => 'ana@example.com',
            'password' => 'wrong',
        ])->assertUnprocessable();
    }

    $this->fromSpa()->postJson('/api/v1/auth/login', [
        'email' => 'ana@example.com',
        'password' => 'password',
    ])->assertTooManyRequests()
        ->assertJsonPath('code', 'too_many_requests')
        ->assertHeader('Retry-After');

    $this->assertGuest('web');
});

it('logs out and removes the login from the session', function () {
    $user = User::factory()->create();
    $loginKey = Auth::guard('web')->getName();

    $this->fromSpa()->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();
    expect(session()->get($loginKey))->toBe($user->id);

    $this->fromSpa()->postJson('/api/v1/auth/logout')->assertNoContent();

    $this->assertGuest('web');
    expect(session()->has($loginKey))->toBeFalse();
});

it('requires authentication to log out', function () {
    $this->fromSpa()->postJson('/api/v1/auth/logout')->assertUnauthorized();
});
