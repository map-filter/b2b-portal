<?php

namespace Tests\Feature\Api;

use App\Models\User;

class AuthTest extends ApiTestCase
{
    public function test_login_returns_token_and_profile(): void
    {
        $user = $this->buyer();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'role', 'company' => ['name', 'contract']]]);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = $this->buyer();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_blocked_user_cannot_use_token(): void
    {
        $user = $this->buyer();
        $token = $user->createToken('t')->plainTextToken;
        User::whereKey($user->id)->update(['is_active' => false]);

        $this->withToken($token)->getJson('/api/auth/me')->assertForbidden();
    }
}
