<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\ChartOfAccount;
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

class PurchaseReturnTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private function actingAsAdmin(): User
    {
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        $this->branch = Branch::firstOrCreate(['name' => 'Main Branch'], ['is_main' => true]);

        foreach (['purchases.manage', 'pos.sell'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $role->givePermissionTo(['purchases.manage', 'pos.sell']);

        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole($role);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function nonImeiSku(): ProductVariantColor
    {
        $type = ProductType::factory()->create(['imei_tracking_default' => false]);

        return ProductVariantColor::factory()->create([
            'product_variant_id' => ProductVariant::factory()->create([
                'product_model_id' => ProductModel::factory()->create(['product_type_id' => $type->id])->id,
            ])->id,
        ]);
    }

    private function imeiSku(): ProductVariantColor
    {
        $type = ProductType::factory()->create(['imei_tracking_default' => true]);

        return ProductVariantColor::factory()->create([
            'product_variant_id' => ProductVariant::factory()->create([
                'product_model_id' => ProductModel::factory()->create(['product_type_id' => $type->id])->id,
            ])->id,
        ]);
    }

    public function test_returning_quantity_stock_decreases_stock_and_reverses_accounting(): void
    {
        $this->actingAsAdmin();
        $sku = $this->nonImeiSku();
        $distributor = Distributor::factory()->create();

        $purchase = $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 5, 'unit_cost' => 500]],
        ])->assertCreated()->json('data');

        $purchaseItemId = $purchase['items'][0]['id'];

        $this->postJson('/api/purchase-returns', [
            'purchase_invoice_id' => $purchase['id'],
            'return_date' => now()->toDateString(),
            'items' => [['purchase_item_id' => $purchaseItemId, 'quantity' => 2]],
        ])->assertCreated();

        $stock = InventoryStock::where('product_variant_color_id', $sku->id)->first();
        $this->assertSame(3, $stock->quantity);

        $batch = PurchaseItem::find($purchaseItemId);
        $this->assertSame(3, $batch->remaining_quantity);

        $payable = ChartOfAccount::where('code', '2000')->firstOrFail();
        $inventory = ChartOfAccount::where('code', '1200')->firstOrFail();
        $this->assertEquals(1500.0, $payable->balance()); // 2500 - 1000 returned
        $this->assertEquals(1500.0, $inventory->balance());
        $this->assertEquals(1500.0, $distributor->fresh()->currentBalance());
    }

    public function test_returning_an_imei_unit_marks_it_returned_and_updates_stock(): void
    {
        $this->actingAsAdmin();
        $sku = $this->imeiSku();
        $distributor = Distributor::factory()->create();

        $purchase = $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [[
                'product_variant_color_id' => $sku->id,
                'quantity' => 1,
                'unit_cost' => 24000,
                'imeis' => [['imei1' => '777777777777777']],
            ]],
        ])->assertCreated()->json('data');

        $unit = ImeiUnit::where('imei1', '777777777777777')->firstOrFail();

        $this->postJson('/api/purchase-returns', [
            'purchase_invoice_id' => $purchase['id'],
            'return_date' => now()->toDateString(),
            'items' => [[
                'purchase_item_id' => $purchase['items'][0]['id'],
                'quantity' => 1,
                'imei_unit_id' => $unit->id,
            ]],
        ])->assertCreated();

        $this->assertSame('returned', $unit->fresh()->status);
    }

    public function test_cannot_return_an_already_sold_imei(): void
    {
        $this->actingAsAdmin();
        $sku = $this->imeiSku();
        $distributor = Distributor::factory()->create();

        $purchase = $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [[
                'product_variant_color_id' => $sku->id,
                'quantity' => 1,
                'unit_cost' => 24000,
                'imeis' => [['imei1' => '888888888888888']],
            ]],
        ])->assertCreated()->json('data');

        $unit = ImeiUnit::where('imei1', '888888888888888')->firstOrFail();
        $cash = \App\Domain\Accounting\Models\PaymentMethod::where('name', 'Cash')->firstOrFail();

        $this->postJson('/api/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [[
                'product_variant_color_id' => $sku->id,
                'imei_unit_id' => $unit->id,
                'quantity' => 1,
                'unit_price' => 25000,
            ]],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => 25000]],
        ])->assertCreated();

        $this->postJson('/api/purchase-returns', [
            'purchase_invoice_id' => $purchase['id'],
            'return_date' => now()->toDateString(),
            'items' => [[
                'purchase_item_id' => $purchase['items'][0]['id'],
                'quantity' => 1,
                'imei_unit_id' => $unit->id,
            ]],
        ])->assertUnprocessable();
    }

    public function test_cannot_return_more_quantity_than_remains_in_the_batch(): void
    {
        $this->actingAsAdmin();
        $sku = $this->nonImeiSku();
        $distributor = Distributor::factory()->create();

        $purchase = $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 5, 'unit_cost' => 500]],
        ])->assertCreated()->json('data');

        $cash = \App\Domain\Accounting\Models\PaymentMethod::where('name', 'Cash')->firstOrFail();
        $this->postJson('/api/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 4, 'unit_price' => 800]],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => 3200]],
        ])->assertCreated();

        // Only 1 unit remains in this batch; trying to return 2 should fail.
        $this->postJson('/api/purchase-returns', [
            'purchase_invoice_id' => $purchase['id'],
            'return_date' => now()->toDateString(),
            'items' => [['purchase_item_id' => $purchase['items'][0]['id'], 'quantity' => 2]],
        ])->assertUnprocessable();
    }
}
