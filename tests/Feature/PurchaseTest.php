<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Models\PaymentMethod;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductModel;
use App\Domain\Catalog\Models\ProductType;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\ProductVariantColor;
use App\Domain\Inventory\Models\ImeiUnit;
use App\Domain\Inventory\Models\InventoryStock;
use App\Domain\Purchasing\Models\Distributor;
use App\Domain\Purchasing\Models\PurchaseItem;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsPurchaser(): User
    {
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(PaymentMethodSeeder::class);

        Permission::firstOrCreate(['name' => 'purchases.manage', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $role->givePermissionTo('purchases.manage');

        $branch = Branch::firstOrCreate(['name' => 'Main Branch'], ['is_main' => true]);

        $user = User::factory()->create(['branch_id' => $branch->id]);
        $user->assignRole($role);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function nonImeiSku(): ProductVariantColor
    {
        $type = ProductType::factory()->create(['imei_tracking_default' => false]);

        return ProductVariantColor::factory()->create([
            'product_variant_id' => ProductVariant::factory()->create([
                'product_model_id' => ProductModel::factory()->create([
                    'product_type_id' => $type->id,
                ])->id,
            ])->id,
        ]);
    }

    private function imeiSku(): ProductVariantColor
    {
        $type = ProductType::factory()->create(['imei_tracking_default' => true]);

        return ProductVariantColor::factory()->create([
            'product_variant_id' => ProductVariant::factory()->create([
                'product_model_id' => ProductModel::factory()->create([
                    'product_type_id' => $type->id,
                ])->id,
            ])->id,
        ]);
    }

    public function test_purchasing_a_quantity_based_sku_increases_stock_and_posts_balanced_accounting(): void
    {
        $this->actingAsPurchaser();
        $distributor = Distributor::factory()->create();
        $sku = $this->nonImeiSku();

        $response = $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [
                ['product_variant_color_id' => $sku->id, 'quantity' => 10, 'unit_cost' => 500],
            ],
        ])->assertCreated();

        $this->assertEquals(5000.0, $response->json('data.total'));

        $stock = InventoryStock::where('product_variant_color_id', $sku->id)->first();
        $this->assertSame(10, $stock->quantity);

        $inventory = ChartOfAccount::where('code', '1200')->firstOrFail();
        $payable = ChartOfAccount::where('code', '2000')->firstOrFail();
        $this->assertSame(5000.0, $inventory->balance());
        $this->assertSame(5000.0, $payable->balance());
        $this->assertSame(5000.0, $distributor->fresh()->currentBalance());
    }

    public function test_purchasing_an_imei_sku_creates_imei_units_in_stock(): void
    {
        $this->actingAsPurchaser();
        $distributor = Distributor::factory()->create();
        $sku = $this->imeiSku();

        $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [[
                'product_variant_color_id' => $sku->id,
                'quantity' => 2,
                'unit_cost' => 24000,
                'imeis' => [
                    ['imei1' => '111111111111111'],
                    ['imei1' => '222222222222222', 'imei2' => '222222222222999'],
                ],
            ]],
        ])->assertCreated();

        $units = ImeiUnit::where('product_variant_color_id', $sku->id)->get();
        $this->assertCount(2, $units);
        $this->assertTrue($units->every(fn ($u) => $u->status === 'in_stock'));
        $this->assertSame(['111111111111111', '222222222222222'], $units->pluck('imei1')->sort()->values()->all());

        // No qty-based cache row should exist for an IMEI-tracked SKU.
        $this->assertDatabaseMissing('inventory_stocks', ['product_variant_color_id' => $sku->id]);
    }

    public function test_demo_device_flag_defaults_to_false_and_can_be_set_per_imei(): void
    {
        $this->actingAsPurchaser();
        $distributor = Distributor::factory()->create();
        $sku = $this->imeiSku();

        $response = $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [[
                'product_variant_color_id' => $sku->id,
                'quantity' => 2,
                'unit_cost' => 24000,
                'imeis' => [
                    ['imei1' => '444444444444444'],
                    ['imei1' => '555555555555555', 'is_demo' => true],
                ],
            ]],
        ])->assertCreated();

        $imeis = collect($response->json('data.items.0.imeis'));
        $this->assertFalse($imeis->firstWhere('imei1', '444444444444444')['is_demo']);
        $this->assertTrue($imeis->firstWhere('imei1', '555555555555555')['is_demo']);

        $this->assertFalse(ImeiUnit::where('imei1', '444444444444444')->firstOrFail()->is_demo);
        $this->assertTrue(ImeiUnit::where('imei1', '555555555555555')->firstOrFail()->is_demo);
    }

    public function test_demo_quantity_can_be_recorded_for_non_imei_purchase_items(): void
    {
        $this->actingAsPurchaser();
        $distributor = Distributor::factory()->create();
        $sku = $this->nonImeiSku();

        $response = $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [[
                'product_variant_color_id' => $sku->id,
                'quantity' => 10,
                'demo_quantity' => 3,
                'unit_cost' => 500,
            ]],
        ])->assertCreated();

        $this->assertSame(3, $response->json('data.items.0.demo_quantity'));

        $stock = InventoryStock::where('product_variant_color_id', $sku->id)->firstOrFail();
        $this->assertSame(10, $stock->quantity);
        $this->assertSame(3, $stock->demo_quantity);
    }

    public function test_demo_quantity_cannot_exceed_purchased_quantity(): void
    {
        $this->actingAsPurchaser();
        $distributor = Distributor::factory()->create();
        $sku = $this->nonImeiSku();

        $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [[
                'product_variant_color_id' => $sku->id,
                'quantity' => 5,
                'demo_quantity' => 6,
                'unit_cost' => 500,
            ]],
        ])->assertStatus(422);
    }

    public function test_imei_count_must_match_quantity(): void
    {
        $this->actingAsPurchaser();
        $distributor = Distributor::factory()->create();
        $sku = $this->imeiSku();

        $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [[
                'product_variant_color_id' => $sku->id,
                'quantity' => 2,
                'unit_cost' => 24000,
                'imeis' => [['imei1' => '111111111111111']],
            ]],
        ])->assertUnprocessable();
    }

    public function test_duplicate_imei_is_rejected(): void
    {
        $this->actingAsPurchaser();
        $distributor = Distributor::factory()->create();
        $sku = $this->imeiSku();

        $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [[
                'product_variant_color_id' => $sku->id,
                'quantity' => 1,
                'unit_cost' => 24000,
                'imeis' => [['imei1' => '333333333333333']],
            ]],
        ])->assertCreated();

        $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [[
                'product_variant_color_id' => $sku->id,
                'quantity' => 1,
                'unit_cost' => 24500,
                'imeis' => [['imei1' => '333333333333333']],
            ]],
        ])->assertUnprocessable();
    }

    public function test_immediate_payment_reduces_distributor_due(): void
    {
        $this->actingAsPurchaser();
        $distributor = Distributor::factory()->create();
        $sku = $this->nonImeiSku();
        $cash = PaymentMethod::where('name', 'Cash')->firstOrFail();

        $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [
                ['product_variant_color_id' => $sku->id, 'quantity' => 1, 'unit_cost' => 30000],
            ],
            'payments' => [
                ['payment_method_id' => $cash->id, 'amount' => 20000],
            ],
        ])->assertCreated();

        $this->assertSame(10000.0, $distributor->fresh()->currentBalance());

        $cashAccount = ChartOfAccount::where('code', '1000')->firstOrFail();
        $this->assertSame(-20000.0, $cashAccount->balance());
    }

    public function test_multiple_purchases_of_the_same_sku_keep_separate_cost_batches(): void
    {
        $this->actingAsPurchaser();
        $distributor = Distributor::factory()->create();
        $sku = $this->nonImeiSku();

        $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 5, 'unit_cost' => 25000]],
        ])->assertCreated();

        $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 3, 'unit_cost' => 24000]],
        ])->assertCreated();

        $batches = PurchaseItem::where('product_variant_color_id', $sku->id)
            ->orderBy('unit_cost')
            ->get();

        $this->assertCount(2, $batches);
        $this->assertEquals(24000, $batches[0]->unit_cost);
        $this->assertEquals(25000, $batches[1]->unit_cost);

        $stock = InventoryStock::where('product_variant_color_id', $sku->id)->first();
        $this->assertSame(8, $stock->quantity);
    }

    public function test_overpayment_is_rejected(): void
    {
        $this->actingAsPurchaser();
        $distributor = Distributor::factory()->create();
        $sku = $this->nonImeiSku();
        $cash = PaymentMethod::where('name', 'Cash')->firstOrFail();

        $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 1, 'unit_cost' => 1000]],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => 5000]],
        ])->assertUnprocessable();
    }
}
