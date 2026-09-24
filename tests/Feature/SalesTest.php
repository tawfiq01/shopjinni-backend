<?php

namespace Tests\Feature;

use App\Domain\Accounting\Models\ChartOfAccount;
use App\Domain\Accounting\Models\PaymentMethod;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductModel;
use App\Domain\Catalog\Models\ProductType;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\ProductVariantColor;
use App\Domain\Companies\Models\Company;
use App\Domain\Customers\Models\Customer;
use App\Domain\Inventory\Models\ImeiUnit;
use App\Domain\Purchasing\Models\Distributor;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SalesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Company $company;

    private function bootLedger(): void
    {
        // Seeded (and the branch created) only now, after actingAs() —
        // every one of these belongs to a tenant model, whose
        // BelongsToCompany scope resolves the company from the now-
        // authenticated user.
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        $this->branch = Branch::firstOrCreate(['name' => 'Main Branch'], ['is_main' => true]);
        auth()->user()->update(['branch_id' => $this->branch->id]);
    }

    private function actingAsAdmin(): User
    {
        foreach (['pos.sell', 'purchases.manage', 'reports.view-cost'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $this->company = $this->createCompany('Test Company');
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $this->assignCompanyRole($this->company, $user, 'Admin', ['pos.sell', 'purchases.manage', 'reports.view-cost']);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function actingAsSalesperson(): User
    {
        Permission::firstOrCreate(['name' => 'pos.sell', 'guard_name' => 'web']);

        $user = User::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id]);
        $this->assignCompanyRole($this->company, $user, 'Salesperson', ['pos.sell']);
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
            'items' => [[
                'product_variant_color_id' => $sku->id,
                'quantity' => $qty,
                'unit_cost' => $unitCost,
                'imeis' => $imeis,
            ]],
        ])->assertCreated();
    }

    public function test_selling_a_quantity_sku_reduces_stock_and_posts_balanced_accounting_with_correct_profit(): void
    {
        $this->actingAsAdmin();
        $this->bootLedger();
        $sku = $this->nonImeiSku();
        $this->purchase($sku, 5, 500); // cost 500 each

        $cash = PaymentMethod::where('name', 'Cash')->firstOrFail();

        $response = $this->postJson('/api/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [
                ['product_variant_color_id' => $sku->id, 'quantity' => 2, 'unit_price' => 800],
            ],
            'payments' => [
                ['payment_method_id' => $cash->id, 'amount' => 1600],
            ],
        ])->assertCreated();

        $this->assertEquals(1600.0, $response->json('data.total'));
        $this->assertEquals(600.0, $response->json('data.profit')); // (800-500)*2

        $stock = \App\Domain\Inventory\Models\InventoryStock::where('product_variant_color_id', $sku->id)->first();
        $this->assertSame(3, $stock->quantity);

        $revenue = ChartOfAccount::where('code', '4000')->firstOrFail();
        $cogs = ChartOfAccount::where('code', '5000')->firstOrFail();
        $inventory = ChartOfAccount::where('code', '1200')->firstOrFail();

        $this->assertEquals(1600.0, $revenue->balance());
        $this->assertEquals(1000.0, $cogs->balance());
        // Inventory: +2500 from purchase (5*500) - 1000 from COGS relief = 1500
        $this->assertEquals(1500.0, $inventory->balance());
    }

    public function test_fifo_uses_oldest_batch_cost_first(): void
    {
        $this->actingAsAdmin();
        $this->bootLedger();
        $sku = $this->nonImeiSku();
        $this->purchase($sku, 2, 500); // batch 1: 2 units @ 500
        $this->purchase($sku, 2, 600); // batch 2: 2 units @ 600

        $cash = PaymentMethod::where('name', 'Cash')->firstOrFail();

        // Sell 3: should consume 2 @ 500 + 1 @ 600 = 1600 total cost, avg 533.33
        $response = $this->postJson('/api/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [
                ['product_variant_color_id' => $sku->id, 'quantity' => 3, 'unit_price' => 700],
            ],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => 2100]],
        ])->assertCreated();

        $this->assertEqualsWithDelta(1600.0, $response->json('data.total_cost'), 0.05);
    }

    public function test_selling_an_imei_unit_marks_it_sold_and_prevents_reselling(): void
    {
        $this->actingAsAdmin();
        $this->bootLedger();
        $sku = $this->imeiSku();
        $this->purchase($sku, 1, 24000, [['imei1' => '999999999999999']]);

        $unit = ImeiUnit::where('imei1', '999999999999999')->firstOrFail();
        $cash = PaymentMethod::where('name', 'Cash')->firstOrFail();

        $this->postJson('/api/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [[
                'product_variant_color_id' => $sku->id,
                'imei_unit_id' => $unit->id,
                'quantity' => 1,
                'unit_price' => 25500,
            ]],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => 25500]],
        ])->assertCreated();

        $this->assertSame('sold', $unit->fresh()->status);

        // Reselling the same (now sold) IMEI must fail.
        $sku2 = $this->imeiSku();
        $this->postJson('/api/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [[
                'product_variant_color_id' => $sku->id,
                'imei_unit_id' => $unit->id,
                'quantity' => 1,
                'unit_price' => 25000,
            ]],
        ])->assertUnprocessable();
    }

    public function test_imei_tracked_item_rejects_quantity_greater_than_one(): void
    {
        $this->actingAsAdmin();
        $this->bootLedger();
        $sku = $this->imeiSku();
        $this->purchase($sku, 2, 24000, [['imei1' => '111111111111111'], ['imei1' => '222222222222222']]);
        $unit = ImeiUnit::where('imei1', '111111111111111')->firstOrFail();

        $this->postJson('/api/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [[
                'product_variant_color_id' => $sku->id,
                'imei_unit_id' => $unit->id,
                'quantity' => 2,
                'unit_price' => 25000,
            ]],
        ])->assertUnprocessable();
    }

    public function test_due_sale_requires_a_customer(): void
    {
        $this->actingAsAdmin();
        $this->bootLedger();
        $sku = $this->nonImeiSku();
        $this->purchase($sku, 5, 500);

        $this->postJson('/api/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 1, 'unit_price' => 800]],
        ])->assertUnprocessable();
    }

    public function test_due_sale_with_customer_updates_their_balance(): void
    {
        $this->actingAsAdmin();
        $this->bootLedger();
        $sku = $this->nonImeiSku();
        $this->purchase($sku, 5, 500);
        $customer = Customer::factory()->create();

        $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 1, 'unit_price' => 800]],
        ])->assertCreated();

        $this->assertEquals(800.0, $customer->fresh()->currentBalance());
    }

    public function test_overpayment_is_rejected(): void
    {
        $this->actingAsAdmin();
        $this->bootLedger();
        $sku = $this->nonImeiSku();
        $this->purchase($sku, 5, 500);
        $cash = PaymentMethod::where('name', 'Cash')->firstOrFail();

        $this->postJson('/api/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 1, 'unit_price' => 800]],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => 5000]],
        ])->assertUnprocessable();
    }

    public function test_insufficient_stock_is_rejected(): void
    {
        $this->actingAsAdmin();
        $this->bootLedger();
        $sku = $this->nonImeiSku();
        $this->purchase($sku, 2, 500);

        $this->postJson('/api/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 5, 'unit_price' => 800]],
        ])->assertUnprocessable();
    }

    public function test_salesperson_response_hides_cost_and_profit(): void
    {
        $this->actingAsAdmin();
        $this->bootLedger();
        $sku = $this->nonImeiSku();
        $this->purchase($sku, 5, 500);

        $this->actingAsSalesperson();
        $cash = PaymentMethod::where('name', 'Cash')->firstOrFail();

        $response = $this->postJson('/api/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 1, 'unit_price' => 800]],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => 800]],
        ])->assertCreated();

        $response->assertJsonMissingPath('data.profit');
        $response->assertJsonMissingPath('data.total_cost');
        $this->assertArrayNotHasKey('unit_cost', $response->json('data.items.0'));
    }

    public function test_pos_search_surfaces_demo_quantity_for_non_imei_skus(): void
    {
        $this->actingAsAdmin();
        $this->bootLedger();
        $sku = $this->nonImeiSku();

        $distributor = Distributor::factory()->create();
        $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [[
                'product_variant_color_id' => $sku->id,
                'quantity' => 10,
                'demo_quantity' => 4,
                'unit_cost' => 500,
            ]],
        ])->assertCreated();

        $response = $this->getJson('/api/pos/search?q='.$sku->sku)->assertOk();
        $candidate = collect($response->json('data'))->firstWhere('product_variant_color_id', $sku->id);

        $this->assertSame(10, $candidate['available_quantity']);
        $this->assertSame(4, $candidate['demo_quantity']);
    }
}
