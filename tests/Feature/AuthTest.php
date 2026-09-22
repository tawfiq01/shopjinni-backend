<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_correct_credentials(): void
    {
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $user = User::factory()->create([
            'password' => Hash::make('secret123'),
        ]);
        $user->assignRole('Admin');

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'roles', 'permissions']])
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('user.roles.0', 'Admin');
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable();
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('secret123'),
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertUnprocessable();
    }

    public function test_authenticated_user_can_fetch_profile(): void
    {
        $user = User::factory()->create();

        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_logout_deletes_the_current_access_token(): void
    {
        $user = User::factory()->create();

        $accessToken = $user->createToken('test');
        $token = $accessToken->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $accessToken->accessToken->id,
        ]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_user_can_change_their_own_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('oldpassword123')]);
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/auth/change-password', [
            'current_password' => 'oldpassword123',
            'new_password' => 'newpassword456',
            'new_password_confirmation' => 'newpassword456',
        ])->assertOk();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'newpassword456',
        ])->assertOk();
    }

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('oldpassword123')]);
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/auth/change-password', [
            'current_password' => 'wrongpassword',
            'new_password' => 'newpassword456',
            'new_password_confirmation' => 'newpassword456',
        ])->assertUnprocessable();
    }

    public function test_change_password_requires_confirmation_to_match(): void
    {
        $user = User::factory()->create(['password' => Hash::make('oldpassword123')]);
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/auth/change-password', [
            'current_password' => 'oldpassword123',
            'new_password' => 'newpassword456',
            'new_password_confirmation' => 'doesnotmatch',
        ])->assertUnprocessable();
    }
}
