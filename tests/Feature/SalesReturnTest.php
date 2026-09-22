<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Models\PaymentMethod;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductModel;
use App\Domain\Catalog\Models\ProductType;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\ProductVariantColor;
use App\Domain\Customers\Models\Customer;
use App\Domain\Inventory\Models\ImeiUnit;
use App\Domain\Inventory\Models\InventoryStock;
use App\Domain\Purchasing\Models\Distributor;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesReturnTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private function bootLedger(): void
    {
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        $this->branch = Branch::firstOrCreate(['name' => 'Main Branch'], ['is_main' => true]);
    }

    private function actingAsAdmin(): User
    {
        foreach (['pos.sell', 'purchases.manage'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $role->givePermissionTo(['pos.sell', 'purchases.manage']);

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

    private function purchase(ProductVariantColor $sku, int $qty, float $unitCost, array $imeis = []): void
    {
        $distributor = Distributor::factory()->create();
        $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => $qty, 'unit_cost' => $unitCost, 'imeis' => $imeis]],
        ])->assertCreated();
    }

    public function test_restocked_quantity_return_reverses_revenue_and_cogs_and_bumps_stock(): void
    {
        $this->bootLedger();
        $this->actingAsAdmin();
        $sku = $this->nonImeiSku();
        $this->purchase($sku, 5, 500);
        $cash = PaymentMethod::where('name', 'Cash')->firstOrFail();

        $sale = $this->postJson('/api/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 2, 'unit_price' => 800]],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => 1600]],
        ])->assertCreated()->json('data');

        $saleItemId = $sale['items'][0]['id'];

        $this->postJson('/api/sales-returns', [
            'sales_invoice_id' => $sale['id'],
            'return_date' => now()->toDateString(),
            'refund_method_id' => $cash->id,
            'items' => [
                ['sale_item_id' => $saleItemId, 'quantity' => 1, 'condition' => 'returned', 'restocked' => true],
            ],
        ])->assertCreated();

        $stock = InventoryStock::where('product_variant_color_id', $sku->id)->first();
        $this->assertSame(4, $stock->quantity); // 5 purchased - 2 sold + 1 returned

        $revenue = ChartOfAccount::where('code', '4000')->firstOrFail();
        $cash_account = ChartOfAccount::where('code', '1000')->firstOrFail();
        $inventory = ChartOfAccount::where('code', '1200')->firstOrFail();

        $this->assertEquals(800.0, $revenue->balance()); // 1600 sale - 800 return
        $this->assertEquals(800.0, $cash_account->balance()); // +1600 sale payment - 800 refund (purchase was on credit)
        $this->assertEquals(2000.0, $inventory->balance()); // 2500 purchase - 1000 COGS(sale) + 500 COGS-reversal(return)
    }

    public function test_damaged_return_does_not_restock_or_reverse_cogs(): void
    {
        $this->bootLedger();
        $this->actingAsAdmin();
        $sku = $this->nonImeiSku();
        $this->purchase($sku, 5, 500);
        $cash = PaymentMethod::where('name', 'Cash')->firstOrFail();

        $sale = $this->postJson('/api/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 2, 'unit_price' => 800]],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => 1600]],
        ])->assertCreated()->json('data');

        $this->postJson('/api/sales-returns', [
            'sales_invoice_id' => $sale['id'],
            'return_date' => now()->toDateString(),
            'refund_method_id' => $cash->id,
            'items' => [
                ['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1, 'condition' => 'damaged', 'restocked' => false],
            ],
        ])->assertCreated();

        $stock = InventoryStock::where('product_variant_color_id', $sku->id)->first();
        $this->assertSame(3, $stock->quantity); // unchanged by the return — item was scrapped

        $inventory = ChartOfAccount::where('code', '1200')->firstOrFail();
        $this->assertEquals(1500.0, $inventory->balance()); // 2500 - 1000 COGS, no reversal
    }

    public function test_imei_return_restocks_the_exact_unit_and_makes_it_sellable_again(): void
    {
        $this->bootLedger();
        $this->actingAsAdmin();
        $sku = $this->imeiSku();
        $this->purchase($sku, 1, 24000, [['imei1' => '555555555555555']]);
        $unit = ImeiUnit::where('imei1', '555555555555555')->firstOrFail();
        $cash = PaymentMethod::where('name', 'Cash')->firstOrFail();

        $sale = $this->postJson('/api/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [[
                'product_variant_color_id' => $sku->id,
                'imei_unit_id' => $unit->id,
                'quantity' => 1,
                'unit_price' => 25500,
            ]],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => 25500]],
        ])->assertCreated()->json('data');

        $this->assertSame('sold', $unit->fresh()->status);

        $this->postJson('/api/sales-returns', [
            'sales_invoice_id' => $sale['id'],
            'return_date' => now()->toDateString(),
            'refund_method_id' => $cash->id,
            'items' => [
                ['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1, 'condition' => 'returned', 'restocked' => true],
            ],
        ])->assertCreated();

        $this->assertSame('in_stock', $unit->fresh()->status);
    }

    public function test_cannot_return_more_than_was_sold(): void
    {
        $this->bootLedger();
        $this->actingAsAdmin();
        $sku = $this->nonImeiSku();
        $this->purchase($sku, 5, 500);
        $cash = PaymentMethod::where('name', 'Cash')->firstOrFail();

        $sale = $this->postJson('/api/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 2, 'unit_price' => 800]],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => 1600]],
        ])->assertCreated()->json('data');

        $this->postJson('/api/sales-returns', [
            'sales_invoice_id' => $sale['id'],
            'return_date' => now()->toDateString(),
            'refund_method_id' => $cash->id,
            'items' => [
                ['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 3, 'condition' => 'returned', 'restocked' => true],
            ],
        ])->assertUnprocessable();
    }

    public function test_walk_in_sale_return_without_refund_method_is_rejected(): void
    {
        $this->bootLedger();
        $this->actingAsAdmin();
        $sku = $this->nonImeiSku();
        $this->purchase($sku, 5, 500);
        $cash = PaymentMethod::where('name', 'Cash')->firstOrFail();

        $sale = $this->postJson('/api/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 1, 'unit_price' => 800]],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => 800]],
        ])->assertCreated()->json('data');

        $this->postJson('/api/sales-returns', [
            'sales_invoice_id' => $sale['id'],
            'return_date' => now()->toDateString(),
            'items' => [
                ['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1, 'condition' => 'returned', 'restocked' => true],
            ],
        ])->assertUnprocessable();
    }

    public function test_due_sale_return_credited_to_customer_account_reduces_their_balance(): void
    {
        $this->bootLedger();
        $this->actingAsAdmin();
        $sku = $this->nonImeiSku();
        $this->purchase($sku, 5, 500);
        $customer = Customer::factory()->create();

        $sale = $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 2, 'unit_price' => 800]],
        ])->assertCreated()->json('data');

        $this->assertEquals(1600.0, $customer->fresh()->currentBalance());

        $this->postJson('/api/sales-returns', [
            'sales_invoice_id' => $sale['id'],
            'return_date' => now()->toDateString(),
            'items' => [
                ['sale_item_id' => $sale['items'][0]['id'], 'quantity' => 1, 'condition' => 'returned', 'restocked' => true],
            ],
        ])->assertCreated();

        $this->assertEquals(800.0, $customer->fresh()->currentBalance());
    }
}
