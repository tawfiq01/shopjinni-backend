<?php

namespace Tests\Feature;

use App\Domain\Branches\Models\Branch;
use App\Domain\Companies\Models\Company;
use App\Domain\Companies\Support\CurrentCompany;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_correct_credentials(): void
    {
        $company = $this->createCompany('Test Company');
        $user = User::factory()->create([
            'company_id' => $company->id,
            'password' => Hash::make('secret123'),
        ]);
        $this->assignCompanyRole($company, $user, 'Admin');

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'roles', 'permissions']])
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('user.roles.0', 'Admin');
    }

    /**
     * Regression test: login() formats the response in the same request
     * that issues the token, before Sanctum has anything to authenticate
     * from — Auth::user() is still null at that point even though $user
     * itself is correct. Branch/Company are both BelongsToCompany-scoped
     * (they read Auth::user()), so without formatUser() explicitly
     * forcing the scope to $user's own company, this silently came back
     * null instead of the real branch name.
     */
    public function test_login_response_includes_the_users_branch_and_company_name(): void
    {
        $company = $this->createCompany('Branch Test Shop');

        $branch = CurrentCompany::forceFor($company->id, fn () => Branch::create([
            'name' => 'Main Branch', 'is_main' => true, 'is_active' => true,
        ]));

        $user = User::factory()->create([
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'password' => Hash::make('secret123'),
        ]);
        $this->assignCompanyRole($company, $user, 'Admin');

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret123',
        ])->assertOk();

        $this->assertSame('Main Branch', $response->json('user.branch_name'));
        $this->assertSame('Branch Test Shop', $response->json('user.company_name'));
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

    public function test_user_can_update_their_own_profile(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $response = $this->putJson('/api/auth/profile', [
            'name' => 'Updated Name',
            'phone' => '01711111111',
            'email' => 'updated@example.com',
        ])->assertOk();

        $response->assertJsonPath('user.name', 'Updated Name')
            ->assertJsonPath('user.phone', '01711111111')
            ->assertJsonPath('user.email', 'updated@example.com');
        $this->assertSame('updated@example.com', $user->fresh()->email);
    }

    public function test_updating_profile_without_changing_email_is_allowed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $this->putJson('/api/auth/profile', [
            'name' => 'Same Email Update',
            'email' => $user->email,
        ])->assertOk()->assertJsonPath('user.name', 'Same Email Update');
    }

    public function test_updating_profile_rejects_an_email_already_used_by_another_account(): void
    {
        $other = User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $this->putJson('/api/auth/profile', [
            'name' => $user->name,
            'email' => 'taken@example.com',
        ])->assertUnprocessable();
    }

    public function test_user_can_upload_and_remove_their_own_avatar(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $file = UploadedFile::fake()->image('avatar.png', 200, 200);
        $response = $this->postJson('/api/auth/profile/avatar', ['avatar' => $file])->assertOk();

        $avatarUrl = $response->json('user.avatar_url');
        $this->assertNotNull($avatarUrl);
        $this->assertStringContainsString('/api/avatars/', $avatarUrl);
        Storage::disk('public')->assertExists($user->fresh()->avatar_path);

        $this->deleteJson('/api/auth/profile/avatar')
            ->assertOk()->assertJsonPath('user.avatar_url', null);
        $this->assertNull($user->fresh()->avatar_path);
    }

    /** Same reasoning as CompanyTest's equivalent logo test — see AuthController::avatarImage()'s docblock. */
    public function test_avatar_image_is_served_publicly_with_a_cors_header(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $file = UploadedFile::fake()->image('avatar.png', 200, 200);
        $avatarUrl = $this->postJson('/api/auth/profile/avatar', ['avatar' => $file])
            ->assertOk()->json('user.avatar_url');
        $filename = basename($avatarUrl);

        $response = $this->get("/api/avatars/{$filename}");

        $response->assertOk();
        $response->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertStringStartsWith('image/', $response->headers->get('Content-Type'));
    }

    public function test_avatar_image_404s_for_an_unknown_filename(): void
    {
        $this->get('/api/avatars/does-not-exist.png')->assertNotFound();
    }

    public function test_a_non_image_file_is_rejected_as_an_avatar(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $file = UploadedFile::fake()->create('not-an-image.pdf', 100);
        $this->postJson('/api/auth/profile/avatar', ['avatar' => $file])->assertUnprocessable();
    }
}
