<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Color;
use App\Domain\Catalog\Models\ProductModel;
use App\Domain\Catalog\Models\ProductType;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Companies\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        Permission::firstOrCreate(['name' => 'catalog.manage', 'guard_name' => 'web']);

        $company = $this->createCompany('Test Company');
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->assignCompanyRole($company, $user, 'Admin', ['catalog.manage']);

        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function actingAsPlainUser(): User
    {
        $company = $this->createCompany('Test Company');
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_user_without_permission_cannot_create_a_brand(): void
    {
        $this->actingAsPlainUser();

        $this->postJson('/api/catalog/brands', ['name' => 'Samsung'])
            ->assertForbidden();
    }

    public function test_admin_can_build_a_full_catalog_entry_end_to_end(): void
    {
        $this->actingAsAdmin();

        $brand = $this->postJson('/api/catalog/brands', ['name' => 'Samsung'])
            ->assertCreated()
            ->json('data');
        // is_active must be a real boolean (not null) the moment it's created —
        // the Flutter models parse it with a strict `as bool` cast and crash
        // (blank white screen) if the backend returns null here.
        $this->assertTrue($brand['is_active']);

        $type = ProductType::factory()->create(['name' => 'Smartphone', 'imei_tracking_default' => true]);

        $model = $this->postJson('/api/catalog/models', [
            'brand_id' => $brand['id'],
            'product_type_id' => $type->id,
            'name' => 'Galaxy A25',
            'warranty_months_default' => 12,
        ])->assertCreated()->json('data');

        $this->assertSame('Samsung', $model['brand']['name']);
        $this->assertTrue($model['resolved_imei_tracking_default']);
        $this->assertTrue($model['is_active']);

        $variant = $this->postJson("/api/catalog/models/{$model['id']}/variants", [
            'ram' => '8GB',
            'storage' => '128GB',
        ])->assertCreated()->json('data');
        $this->assertTrue($variant['is_active']);

        $black = Color::factory()->create(['name' => 'Black']);
        $blue = Color::factory()->create(['name' => 'Blue']);

        $blackSku = $this->postJson("/api/catalog/variants/{$variant['id']}/colors", [
            'color_id' => $black->id,
        ])->assertCreated()->json('data');

        $blueSku = $this->postJson("/api/catalog/variants/{$variant['id']}/colors", [
            'color_id' => $blue->id,
            'sku' => 'A25-8-128-BLUE',
        ])->assertCreated()->json('data');

        $this->assertNotEmpty($blackSku['sku']);
        $this->assertSame('A25-8-128-BLUE', $blueSku['sku']);
        $this->assertTrue($blackSku['imei_tracking_enabled']);
        $this->assertTrue($blackSku['is_active']);
        $this->assertTrue($blueSku['is_active']);

        // Full hierarchy is visible from the model detail endpoint.
        $shown = $this->getJson("/api/catalog/models/{$model['id']}")->assertOk()->json('data');
        $this->assertCount(1, $shown['variants']);
        $this->assertCount(2, $shown['variants'][0]['colors']);

        // Searchable across the flat product list.
        $this->getJson('/api/catalog/products?search=A25-8-128-BLUE')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_duplicate_sku_is_rejected(): void
    {
        $this->actingAsAdmin();

        $variant = ProductVariant::factory()->create();
        $color = Color::factory()->create();
        $otherColor = Color::factory()->create();

        $this->postJson("/api/catalog/variants/{$variant->id}/colors", [
            'color_id' => $color->id,
            'sku' => 'DUP-SKU',
        ])->assertCreated();

        $this->postJson("/api/catalog/variants/{$variant->id}/colors", [
            'color_id' => $otherColor->id,
            'sku' => 'DUP-SKU',
        ])->assertUnprocessable();
    }

    public function test_same_variant_color_pair_cannot_be_added_twice(): void
    {
        $this->actingAsAdmin();

        $variant = ProductVariant::factory()->create();
        $color = Color::factory()->create();

        $this->postJson("/api/catalog/variants/{$variant->id}/colors", ['color_id' => $color->id])
            ->assertCreated();

        $this->postJson("/api/catalog/variants/{$variant->id}/colors", ['color_id' => $color->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('color_id');
    }

    public function test_model_cannot_be_deleted_while_it_has_variants(): void
    {
        $this->actingAsAdmin();

        $variant = ProductVariant::factory()->create();
        $model = ProductModel::find($variant->product_model_id);

        $this->deleteJson("/api/catalog/models/{$model->id}")
            ->assertStatus(409);
    }
}
