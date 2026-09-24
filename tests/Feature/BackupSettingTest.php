<?php

namespace Tests\Feature;

use App\Domain\Backup\Models\BackupSetting;
use App\Domain\Companies\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class BackupSettingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        Permission::firstOrCreate(['name' => 'backup.manage', 'guard_name' => 'web']);

        $company = $this->createCompany('Test Company');
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->assignCompanyRole($company, $user, 'Admin', ['backup.manage']);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function actingAsSalesperson(): User
    {
        $company = $this->createCompany('Test Company');
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_non_admin_cannot_access_backup_settings(): void
    {
        $this->actingAsSalesperson();

        $this->getJson('/api/backup/status')->assertForbidden();
        $this->postJson('/api/backup/settings', ['is_enabled' => false])->assertForbidden();
        $this->postJson('/api/backup/run-now')->assertForbidden();
    }

    public function test_status_reflects_not_connected_by_default(): void
    {
        $this->actingAsAdmin();

        $response = $this->getJson('/api/backup/status')->assertOk();

        $this->assertFalse($response->json('google_connected'));
        $this->assertFalse($response->json('is_enabled'));
        $this->assertNull($response->json('last_backup_status'));
    }

    public function test_enabling_automatic_backup_requires_a_time(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/backup/settings', [
            'is_enabled' => true,
        ])->assertUnprocessable();
    }

    public function test_settings_are_saved(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/backup/settings', [
            'drive_folder_id' => 'abc123',
            'drive_folder_name' => 'MobiShop Backups',
            'backup_time' => '02:30',
            'is_enabled' => true,
        ])->assertOk();

        $setting = BackupSetting::current();
        $this->assertSame('abc123', $setting->drive_folder_id);
        $this->assertSame('MobiShop Backups', $setting->drive_folder_name);
        $this->assertSame('02:30', $setting->backup_time);
        $this->assertTrue($setting->is_enabled);

        $status = $this->getJson('/api/backup/status')->assertOk();
        $this->assertSame('02:30', $status->json('backup_time'));
    }

    public function test_run_now_requires_google_to_be_connected_first(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/backup/run-now')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Connect Google Drive before running a backup.');
    }
}
