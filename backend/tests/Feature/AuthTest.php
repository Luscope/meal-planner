<?php

use App\Models\FamilyMember;
use App\Models\Household;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutMiddleware(ThrottleRequests::class));

test('registering creates a household, user and linked family member, and returns a usable token', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Luisa',
        'email' => 'luisa@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
        'household_name' => 'Andrejess-Familie',
    ]);

    $response->assertCreated()
        ->assertJsonPath('user.email', 'luisa@example.com')
        ->assertJsonPath('user.household.name', 'Andrejess-Familie');

    $user = User::where('email', 'luisa@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->household_id)->not->toBeNull()
        ->and(FamilyMember::where('user_id', $user->id)->exists())->toBeTrue();

    $token = $response->json('token');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('email', 'luisa@example.com');
});

test('a new household gets a unique invite code on registration', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Luisa',
        'email' => 'luisa@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ]);

    $response->assertCreated();

    $household = User::where('email', 'luisa@example.com')->first()->household;

    expect($household->invite_code)->not->toBeEmpty()
        ->and(strlen($household->invite_code))->toBe(8);
});

test('registering with a valid invite code joins the existing household instead of creating a new one', function () {
    $household = Household::factory()->create(['invite_code' => 'JOINME12']);
    $existingMember = User::factory()->for($household)->create();

    $response = $this->postJson('/api/register', [
        'name' => 'Jess',
        'email' => 'jess@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
        'invite_code' => 'joinme12',
    ]);

    $response->assertCreated()
        ->assertJsonPath('user.household.id', $household->id);

    $newUser = User::where('email', 'jess@example.com')->first();

    expect($newUser->household_id)->toBe($household->id)
        ->and(Household::count())->toBe(1)
        ->and(FamilyMember::where('user_id', $newUser->id)->where('household_id', $household->id)->exists())->toBeTrue()
        ->and(User::where('id', $existingMember->id)->exists())->toBeTrue();
});

test('registration fails with an unknown invite code and creates no user or household', function () {
    $this->postJson('/api/register', [
        'name' => 'Jess',
        'email' => 'jess@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
        'invite_code' => 'DOESNOTEXIST',
    ])->assertStatus(422);

    expect(User::where('email', 'jess@example.com')->exists())->toBeFalse();
});

test('registration fails when password confirmation does not match', function () {
    $this->postJson('/api/register', [
        'name' => 'Luisa',
        'email' => 'luisa@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'something-else',
    ])->assertStatus(422);

    expect(User::where('email', 'luisa@example.com')->exists())->toBeFalse();
});

test('registration fails for an already-used email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->postJson('/api/register', [
        'name' => 'Luisa',
        'email' => 'taken@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ])->assertStatus(422);
});

test('login succeeds with correct credentials and returns a token', function () {
    User::factory()->create([
        'email' => 'luisa@example.com',
        'password' => 'correct-horse-battery-staple',
    ]);

    $response = $this->postJson('/api/login', [
        'email' => 'luisa@example.com',
        'password' => 'correct-horse-battery-staple',
    ]);

    $response->assertOk()->assertJsonStructure(['user', 'token']);
});

test('login fails with an incorrect password', function () {
    User::factory()->create([
        'email' => 'luisa@example.com',
        'password' => 'correct-horse-battery-staple',
    ]);

    $this->postJson('/api/login', [
        'email' => 'luisa@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(422);
});

test('logout revokes the current token', function () {
    $user = User::factory()->create();
    $newToken = $user->createToken('api');

    $this->withHeader('Authorization', "Bearer {$newToken->plainTextToken}")
        ->postJson('/api/logout')
        ->assertNoContent();

    expect(PersonalAccessToken::find($newToken->accessToken->id))->toBeNull();
});

test('requesting a password reset for a registered email queues a reset notification', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'luisa@example.com']);

    $this->postJson('/api/forgot-password', ['email' => 'luisa@example.com'])
        ->assertOk()
        ->assertJsonStructure(['message']);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('requesting a password reset for an unknown email still returns a generic success response', function () {
    Notification::fake();

    $response = $this->postJson('/api/forgot-password', ['email' => 'unbekannt@example.com']);

    $response->assertOk()->assertJsonStructure(['message']);
    Notification::assertNothingSent();
});

test('resets the password with a valid token and revokes existing tokens', function () {
    $user = User::factory()->create(['email' => 'luisa@example.com', 'password' => 'old-password-123']);
    $existingToken = $user->createToken('api');
    $token = Password::createToken($user);

    $response = $this->postJson('/api/reset-password', [
        'token' => $token,
        'email' => 'luisa@example.com',
        'password' => 'new-password-456',
        'password_confirmation' => 'new-password-456',
    ]);

    $response->assertOk();

    expect(PersonalAccessToken::find($existingToken->accessToken->id))->toBeNull();

    $this->postJson('/api/login', [
        'email' => 'luisa@example.com',
        'password' => 'new-password-456',
    ])->assertOk();
});

test('rejects resetting the password with an invalid token', function () {
    $user = User::factory()->create(['email' => 'luisa@example.com']);

    $this->postJson('/api/reset-password', [
        'token' => 'not-a-real-token',
        'email' => 'luisa@example.com',
        'password' => 'new-password-456',
        'password_confirmation' => 'new-password-456',
    ])->assertStatus(422);

    expect(Hash::check('new-password-456', $user->fresh()->password))->toBeFalse();
});
