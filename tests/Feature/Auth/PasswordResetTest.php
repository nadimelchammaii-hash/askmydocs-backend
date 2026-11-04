<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

it('emails a reset link that points to the SPA', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ana@example.com']);

    $this->fromSpa()->postJson('/api/v1/auth/forgot-password', ['email' => 'Ana@example.com'])
        ->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $url = $notification->toMail($user)->actionUrl;

        return str_starts_with($url, config('app.frontend_url').'/reset-password?')
            && str_contains($url, 'token='.$notification->token);
    });
});

it('gives the same response for unknown emails and sends nothing', function () {
    Notification::fake();
    User::factory()->create(['email' => 'ana@example.com']);

    $known = $this->fromSpa()->postJson('/api/v1/auth/forgot-password', ['email' => 'ana@example.com']);
    $unknown = $this->fromSpa()->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com']);

    $known->assertOk();
    $unknown->assertOk();
    expect($unknown->json())->toBe($known->json());
    Notification::assertSentTimes(ResetPassword::class, 1);
});

it('resets the password with a valid token', function () {
    $user = User::factory()->create(['email' => 'ana@example.com']);
    $token = Password::createToken($user);

    $this->fromSpa()->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => 'ana@example.com',
        'password' => 'NewSecret123',
        'password_confirmation' => 'NewSecret123',
    ])->assertOk();

    expect(Hash::check('NewSecret123', $user->fresh()->password))->toBeTrue();
});

it('rejects an invalid token and an unknown email with the same error', function () {
    User::factory()->create(['email' => 'ana@example.com']);
    $payload = ['password' => 'NewSecret123', 'password_confirmation' => 'NewSecret123', 'token' => 'invalid-token'];

    $badToken = $this->fromSpa()->postJson('/api/v1/auth/reset-password', [...$payload, 'email' => 'ana@example.com'])
        ->assertUnprocessable();
    $unknownEmail = $this->fromSpa()->postJson('/api/v1/auth/reset-password', [...$payload, 'email' => 'nobody@example.com'])
        ->assertUnprocessable();

    expect($badToken->json('errors'))->toBe($unknownEmail->json('errors'));
});
