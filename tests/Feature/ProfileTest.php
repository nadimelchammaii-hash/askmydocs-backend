<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('returns the authenticated user without sensitive fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/user')
        ->assertOk()
        ->assertExactJson(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'created_at' => $user->created_at->toIso8601String(),
        ]]);
});

it('rejects unauthenticated access to the profile', function () {
    $this->getJson('/api/v1/user')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
});

it('updates the name and normalised email', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patchJson('/api/v1/user', ['name' => 'New Name', 'email' => 'New@Example.com'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.email', 'new@example.com');

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('does not allow taking another user\'s email', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create();

    $this->actingAs($user)->patchJson('/api/v1/user', ['email' => 'TAKEN@example.com'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

it('changes the password when the current password is correct', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/v1/user/password', [
        'current_password' => 'password',
        'password' => 'NewSecret123',
        'password_confirmation' => 'NewSecret123',
    ])->assertNoContent();

    expect(Hash::check('NewSecret123', $user->fresh()->password))->toBeTrue();
});

it('refuses to change the password when the current password is wrong', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/v1/user/password', [
        'current_password' => 'wrong',
        'password' => 'NewSecret123',
        'password_confirmation' => 'NewSecret123',
    ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});
