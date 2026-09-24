<?php

namespace Tests\Feature;

use App\Domain\Companies\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CompanyTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(string $companyName = 'Test Company'): User
    {
        Permission::firstOrCreate(['name' => 'company.manage', 'guard_name' => 'web']);

        $company = $this->createCompany($companyName);
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->assignCompanyRole($company, $user, 'Admin', ['company.manage']);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function actingAsSalesperson(?int $companyId = null): User
    {
        $company = $companyId ? Company::find($companyId) : $this->createCompany('Other Company');
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->assignCompanyRole($company, $user, 'Salesperson');
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_any_authenticated_user_can_view_their_own_company(): void
    {
        $this->actingAsAdmin('My Phone Shop');

        $response = $this->getJson('/api/company')->assertOk();
        $this->assertSame('My Phone Shop', $response->json('name'));
        $this->assertNull($response->json('logo_url'));
    }

    public function test_non_admin_cannot_update_company_details(): void
    {
        $this->actingAsSalesperson();

        $this->putJson('/api/company', ['name' => 'Hijacked Name'])->assertForbidden();
        $this->postJson('/api/company/logo', [])->assertForbidden();
    }

    public function test_admin_can_update_company_details(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/company', [
            'name' => 'Renamed Shop',
            'address' => 'New Address',
            'phone' => '01700000000',
        ])->assertOk()->assertJsonPath('name', 'Renamed Shop');

        $this->assertSame('Renamed Shop', Company::first()->name);
    }

    public function test_admin_can_upload_and_remove_a_logo(): void
    {
        Storage::fake('public');
        $admin = $this->actingAsAdmin();

        $file = UploadedFile::fake()->image('logo.png', 200, 200);
        $response = $this->postJson('/api/company/logo', ['logo' => $file])->assertOk();

        $logoUrl = $response->json('logo_url');
        $this->assertNotNull($logoUrl);
        $this->assertStringContainsString('/api/logo/', $logoUrl);

        $company = $admin->company->fresh();
        Storage::disk('public')->assertExists($company->logo_path);

        $this->deleteJson('/api/company/logo')->assertOk()->assertJsonPath('logo_url', null);
        Storage::disk('public')->assertMissing($company->logo_path);
    }

    /**
     * The logo is served through an actual route (not the raw /storage/*
     * symlink) specifically so this header can be set — PHP's built-in dev
     * server serves an existing static file directly, bypassing the
     * framework (and any CORS middleware) entirely, which breaks a Flutter
     * Web NetworkImage load fetching cross-origin (app on one port, API on
     * another).
     */
    public function test_logo_image_is_served_publicly_with_a_cors_header(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $file = UploadedFile::fake()->image('logo.png', 200, 200);
        $logoUrl = $this->postJson('/api/company/logo', ['logo' => $file])
            ->assertOk()->json('logo_url');
        $filename = basename($logoUrl);

        // No Authorization header at all — this must work unauthenticated.
        $response = $this->get("/api/logo/{$filename}");

        $response->assertOk();
        $response->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertStringStartsWith('image/', $response->headers->get('Content-Type'));
    }

    public function test_logo_image_404s_for_an_unknown_filename(): void
    {
        $this->get('/api/logo/does-not-exist.png')->assertNotFound();
    }

    public function test_a_non_image_file_is_rejected_as_a_logo(): void
    {
        Storage::fake('public');
        $this->actingAsAdmin();

        $file = UploadedFile::fake()->create('not-an-image.pdf', 100);
        $this->postJson('/api/company/logo', ['logo' => $file])->assertUnprocessable();
    }

    public function test_companies_only_ever_see_and_edit_their_own_details(): void
    {
        $adminA = $this->actingAsAdmin('Shop A');

        $this->actingAsSalesperson($adminA->company_id); // same company as adminA, different role
        $this->getJson('/api/company')->assertOk()->assertJsonPath('name', 'Shop A');

        $adminB = $this->actingAsAdmin('Shop B');
        $this->getJson('/api/company')->assertOk()->assertJsonPath('name', 'Shop B');

        $this->putJson('/api/company', ['name' => 'Shop B Renamed'])->assertOk();
        $this->assertSame('Shop A', $adminA->company->fresh()->name);
    }
}
