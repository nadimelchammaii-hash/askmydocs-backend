<?php

use App\Models\User;

it('registers a user and starts an authenticated session', function () {
    $response = $this->fromSpa()->postJson('/api/v1/auth/register', [
        'name' => 'Ana Example',
        'email' => 'Ana@Example.com',
        'password' => 'Secret123',
        'password_confirmation' => 'Secret123',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Ana Example')
        ->assertJsonPath('data.email', 'ana@example.com')
        ->assertJsonMissingPath('data.password');

    $this->assertAuthenticated('web');
    expect(User::where('email', 'ana@example.com')->exists())->toBeTrue();
});

it('rejects an email that is already registered, regardless of casing', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    $this->fromSpa()->postJson('/api/v1/auth/register', [
        'name' => 'Ana Again',
        'email' => 'ANA@example.com',
        'password' => 'Secret123',
        'password_confirmation' => 'Secret123',
    ])->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors('email');

    $this->assertGuest('web');
});

it('rejects weak or unconfirmed passwords', function (string $password, string $confirmation) {
    $this->fromSpa()->postJson('/api/v1/auth/register', [
        'name' => 'Ana',
        'email' => 'ana@example.com',
        'password' => $password,
        'password_confirmation' => $confirmation,
    ])->assertUnprocessable()->assertJsonValidationErrors('password');
})->with([
    'too short' => ['Ab1', 'Ab1'],
    'no uppercase' => ['secret123', 'secret123'],
    'no number' => ['SecretPass', 'SecretPass'],
    'confirmation mismatch' => ['Secret123', 'Secret124'],
]);
