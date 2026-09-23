<?php

namespace Tests\Feature;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductModel;
use App\Domain\Catalog\Models\ProductType;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\ProductVariantColor;
use App\Domain\Customers\Models\Customer;
use App\Domain\Purchasing\Models\Distributor;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportsTest extends TestCase
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
        foreach (['pos.sell', 'purchases.manage', 'reports.view', 'reports.view-cost', 'distributors.manage', 'customers.manage'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $role->givePermissionTo(['pos.sell', 'purchases.manage', 'reports.view', 'reports.view-cost', 'distributors.manage', 'customers.manage']);

        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole($role);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function actingAsSalesperson(): User
    {
        foreach (['pos.sell', 'reports.view'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $role = Role::firstOrCreate(['name' => 'Salesperson', 'guard_name' => 'web']);
        $role->givePermissionTo(['pos.sell', 'reports.view']);

        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole($role);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function nonImeiSku(): ProductVariantColor
    {
        $type = ProductType::factory()->create(['imei_tracking_default' => false]);

        return ProductVariantColor::factory()->create([
            'reorder_level' => 3,
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

    public function test_dashboard_summary_aggregates_todays_sales_dues_and_low_stock(): void
    {
        $this->bootLedger();
        $this->actingAsAdmin();
        $sku = $this->nonImeiSku(); // reorder_level = 3
        $distributor = Distributor::factory()->create();

        $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 4, 'unit_cost' => 500]],
        ])->assertCreated();

        $cash = \App\Domain\Accounting\Models\PaymentMethod::where('name', 'Cash')->firstOrFail();
        $this->postJson('/api/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 2, 'unit_price' => 800]],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => 1600]],
        ])->assertCreated();

        $this->postJson('/api/customers', ['name' => 'Due Customer', 'mobile' => '017', 'opening_balance' => 500])
            ->assertCreated();

        $adminResponse = $this->getJson('/api/dashboard/summary')->assertOk();
        $this->assertEquals(1600.0, $adminResponse->json('today.sales_total'));
        $this->assertSame(1, $adminResponse->json('today.sales_count'));
        $this->assertEquals(600.0, $adminResponse->json('today.profit'));
        $this->assertEquals(1600.0, $adminResponse->json('this_month.sales_total'));
        $this->assertEquals(500.0, $adminResponse->json('total_customer_due'));
        // 4 purchased - 2 sold = 2 remaining, reorder_level = 3 → low stock.
        $this->assertSame(1, $adminResponse->json('low_stock_count'));

        $this->actingAsSalesperson();
        $spResponse = $this->getJson('/api/dashboard/summary')->assertOk();
        $spResponse->assertJsonMissingPath('today.profit');
    }

    public function test_stock_report_includes_product_type_and_searches_by_brand_and_color(): void
    {
        $this->bootLedger();
        $this->actingAsAdmin();

        $type = ProductType::factory()->create(['name' => 'Accessory', 'imei_tracking_default' => false]);
        $brand = \App\Domain\Catalog\Models\Brand::factory()->create(['name' => 'SearchBrand']);
        $color = \App\Domain\Catalog\Models\Color::factory()->create(['name' => 'Midnight']);
        $sku = ProductVariantColor::factory()->create([
            'color_id' => $color->id,
            'product_variant_id' => ProductVariant::factory()->create([
                'product_model_id' => ProductModel::factory()->create([
                    'product_type_id' => $type->id,
                    'brand_id' => $brand->id,
                ])->id,
            ])->id,
        ]);

        $response = $this->getJson('/api/reports/stock')->assertOk();
        $row = collect($response->json('data'))->firstWhere('sku_id', $sku->id);
        $this->assertSame('Accessory', $row['product_type']);

        $byBrand = $this->getJson('/api/reports/stock?search=SearchBrand')->assertOk();
        $this->assertTrue(collect($byBrand->json('data'))->contains('sku_id', $sku->id));

        $byColor = $this->getJson('/api/reports/stock?search=Midnight')->assertOk();
        $this->assertTrue(collect($byColor->json('data'))->contains('sku_id', $sku->id));

        $noMatch = $this->getJson('/api/reports/stock?search=NoSuchProductXYZ')->assertOk();
        $this->assertFalse(collect($noMatch->json('data'))->contains('sku_id', $sku->id));
    }

    public function test_stock_report_flags_low_stock(): void
    {
        $this->bootLedger();
        $this->actingAsAdmin();
        $sku = $this->nonImeiSku(); // reorder_level = 3
        $distributor = Distributor::factory()->create();

        $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 2, 'unit_cost' => 500]],
        ])->assertCreated();

        $response = $this->getJson('/api/reports/stock')->assertOk();
        $row = collect($response->json('data'))->firstWhere('sku_id', $sku->id);

        $this->assertSame(2, $row['quantity']);
        $this->assertTrue($row['is_low_stock']);
    }

    public function test_stock_report_breaks_out_demo_unit_count(): void
    {
        $this->bootLedger();
        $this->actingAsAdmin();
        $sku = $this->imeiSku();
        $distributor = Distributor::factory()->create();

        $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [[
                'product_variant_color_id' => $sku->id,
                'quantity' => 3,
                'unit_cost' => 24000,
                'imeis' => [
                    ['imei1' => '600000000000001'],
                    ['imei1' => '600000000000002', 'is_demo' => true],
                    ['imei1' => '600000000000003', 'is_demo' => true],
                ],
            ]],
        ])->assertCreated();

        $response = $this->getJson('/api/reports/stock')->assertOk();
        $row = collect($response->json('data'))->firstWhere('sku_id', $sku->id);

        $this->assertSame(3, $row['quantity']);
        $this->assertSame(2, $row['demo_quantity']);
    }

    public function test_stock_report_breaks_out_demo_quantity_for_non_imei_skus(): void
    {
        $this->bootLedger();
        $this->actingAsAdmin();
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

        $response = $this->getJson('/api/reports/stock')->assertOk();
        $row = collect($response->json('data'))->firstWhere('sku_id', $sku->id);

        $this->assertSame(10, $row['quantity']);
        $this->assertSame(4, $row['demo_quantity']);
    }

    public function test_sales_report_summary_totals_and_hides_profit_from_salesperson(): void
    {
        $this->bootLedger();
        $this->actingAsAdmin();
        $sku = $this->nonImeiSku();
        $distributor = Distributor::factory()->create();

        $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 5, 'unit_cost' => 500]],
        ])->assertCreated();

        $cash = \App\Domain\Accounting\Models\PaymentMethod::where('name', 'Cash')->firstOrFail();
        $this->postJson('/api/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 2, 'unit_price' => 800]],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => 1600]],
        ])->assertCreated();

        $adminResponse = $this->getJson('/api/reports/sales/summary')->assertOk();
        $this->assertEquals(1600.0, $adminResponse->json('total_sales'));
        $this->assertEquals(600.0, $adminResponse->json('total_profit'));

        $this->actingAsSalesperson();
        $spResponse = $this->getJson('/api/reports/sales/summary')->assertOk();
        $spResponse->assertJsonMissingPath('total_profit');
    }

    public function test_sales_details_report_lists_line_items_and_hides_cost_from_salesperson(): void
    {
        $this->bootLedger();
        $this->actingAsAdmin();
        $sku = $this->nonImeiSku();
        $distributor = Distributor::factory()->create();

        $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 5, 'unit_cost' => 500]],
        ])->assertCreated();

        $cash = \App\Domain\Accounting\Models\PaymentMethod::where('name', 'Cash')->firstOrFail();
        $this->postJson('/api/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 2, 'unit_price' => 800]],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => 1600]],
        ])->assertCreated();

        $adminResponse = $this->getJson('/api/reports/sales/details')->assertOk();
        $this->assertSame(2, $adminResponse->json('total_quantity'));
        $this->assertEquals(1600.0, $adminResponse->json('total_sales'));
        $this->assertEquals(600.0, $adminResponse->json('total_profit'));
        $rows = $adminResponse->json('rows');
        $this->assertCount(1, $rows);
        $this->assertEquals(600.0, $rows[0]['profit']);
        $this->assertEquals(500.0, $rows[0]['unit_cost']);

        $this->actingAsSalesperson();
        $spResponse = $this->getJson('/api/reports/sales/details')->assertOk();
        $spResponse->assertJsonMissingPath('total_profit');
        $this->assertArrayNotHasKey('unit_cost', $spResponse->json('rows.0'));
        $this->assertArrayNotHasKey('profit', $spResponse->json('rows.0'));
    }

    public function test_sales_details_export_downloads_an_xlsx_workbook(): void
    {
        $this->bootLedger();
        $this->actingAsAdmin();
        $sku = $this->nonImeiSku();
        $distributor = Distributor::factory()->create();

        $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 5, 'unit_cost' => 500]],
        ])->assertCreated();

        $cash = \App\Domain\Accounting\Models\PaymentMethod::where('name', 'Cash')->firstOrFail();
        $this->postJson('/api/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 2, 'unit_price' => 800]],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => 1600]],
        ])->assertCreated();

        $response = $this->get('/api/reports/sales/details/export');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertNotEmpty($response->streamedContent());
    }

    public function test_purchase_report_requires_cost_visibility_permission(): void
    {
        $this->bootLedger();
        $this->actingAsAdmin();
        $sku = $this->nonImeiSku();
        $distributor = Distributor::factory()->create();

        $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 5, 'unit_cost' => 500]],
        ])->assertCreated();

        $response = $this->getJson('/api/reports/purchases/summary')->assertOk();
        $this->assertEquals(2500.0, $response->json('total_purchases'));

        $this->actingAsSalesperson();
        $this->getJson('/api/reports/purchases/summary')->assertForbidden();
    }

    public function test_purchase_report_breaks_down_by_product_and_exposes_price_history(): void
    {
        $this->bootLedger();
        $this->actingAsAdmin();
        $sku = $this->nonImeiSku();
        $distributorA = Distributor::factory()->create(['name' => 'Distributor A']);
        $distributorB = Distributor::factory()->create(['name' => 'Distributor B']);

        $this->postJson('/api/purchases', [
            'distributor_id' => $distributorA->id,
            'purchase_date' => now()->subDays(2)->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 3, 'unit_cost' => 500]],
        ])->assertCreated();

        $this->postJson('/api/purchases', [
            'distributor_id' => $distributorB->id,
            'purchase_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 2, 'unit_cost' => 550]],
        ])->assertCreated();

        $summary = $this->getJson('/api/reports/purchases/summary')->assertOk();
        $productRow = collect($summary->json('by_product'))->firstWhere('sku_id', $sku->id);
        $this->assertSame(5, $productRow['quantity']);
        $this->assertEquals(1500.0 + 1100.0, $productRow['total']); // 3*500 + 2*550
        $this->assertEquals(520.0, $productRow['avg_unit_cost']); // 2600 / 5

        $history = $this->getJson("/api/reports/purchases/price-history?sku_id={$sku->id}")->assertOk();
        $rows = $history->json('data');
        $this->assertCount(2, $rows);
        // Newest batch (Distributor B, 550) first — ordered by id desc.
        $this->assertEquals(550.0, $rows[0]['unit_cost']);
        $this->assertSame('Distributor B', $rows[0]['distributor_name']);
        $this->assertEquals(500.0, $rows[1]['unit_cost']);
        $this->assertSame('Distributor A', $rows[1]['distributor_name']);

        $this->actingAsSalesperson();
        $this->getJson("/api/reports/purchases/price-history?sku_id={$sku->id}")->assertForbidden();
    }

    public function test_due_report_lists_only_non_zero_balances(): void
    {
        $this->bootLedger();
        $this->actingAsAdmin();

        $this->postJson('/api/customers', ['name' => 'Due Customer', 'mobile' => '017', 'opening_balance' => 1500])
            ->assertCreated();
        $this->postJson('/api/customers', ['name' => 'Settled Customer', 'mobile' => '018'])
            ->assertCreated();

        $response = $this->getJson('/api/reports/dues')->assertOk();
        $names = collect($response->json('customers'))->pluck('name');

        $this->assertTrue($names->contains('Due Customer'));
        $this->assertFalse($names->contains('Settled Customer'));
        $this->assertEquals(1500.0, $response->json('total_customer_due'));
    }

    public function test_cash_position_reflects_payments(): void
    {
        $this->bootLedger();
        $this->actingAsAdmin();
        $sku = $this->nonImeiSku();
        $distributor = Distributor::factory()->create();
        $cash = \App\Domain\Accounting\Models\PaymentMethod::where('name', 'Cash')->firstOrFail();

        $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 5, 'unit_cost' => 500]],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => 2500]],
        ])->assertCreated();

        $response = $this->getJson('/api/reports/cash-position')->assertOk();
        $cashRow = collect($response->json('data'))->firstWhere('method', 'Cash');

        $this->assertEquals(-2500.0, $cashRow['balance']);
    }

    public function test_stock_report_includes_valuation_but_hides_it_from_salesperson(): void
    {
        $this->bootLedger();
        $this->actingAsAdmin();
        $sku = $this->nonImeiSku();
        $distributor = Distributor::factory()->create();

        $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 4, 'unit_cost' => 500]],
        ])->assertCreated();

        $adminResponse = $this->getJson('/api/reports/stock')->assertOk();
        $row = collect($adminResponse->json('data'))->firstWhere('sku_id', $sku->id);
        $this->assertEquals(2000.0, $row['value']); // 4 * 500
        $this->assertEquals(2000.0, $adminResponse->json('total_value'));

        $this->actingAsSalesperson();
        $spResponse = $this->getJson('/api/reports/stock')->assertOk();
        $spRow = collect($spResponse->json('data'))->firstWhere('sku_id', $sku->id);
        $this->assertArrayNotHasKey('value', $spRow);
        $spResponse->assertJsonMissingPath('total_value');
    }

    public function test_stock_movements_report_lists_quantity_sku_ledger_and_hides_cost_from_salesperson(): void
    {
        $this->bootLedger();
        $this->actingAsAdmin();
        $sku = $this->nonImeiSku();
        $distributor = Distributor::factory()->create();

        $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 5, 'unit_cost' => 500]],
        ])->assertCreated();

        $cash = \App\Domain\Accounting\Models\PaymentMethod::where('name', 'Cash')->firstOrFail();
        $this->postJson('/api/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 2, 'unit_price' => 800]],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => 1600]],
        ])->assertCreated();

        $adminResponse = $this->getJson("/api/reports/stock-movements?sku_id={$sku->id}")->assertOk();
        $movements = $adminResponse->json('movements');
        $this->assertSame(['purchase', 'sale'], collect($movements)->pluck('type')->all());
        $this->assertSame(5, $movements[0]['quantity_change']);
        $this->assertSame(-2, $movements[1]['quantity_change']);
        $this->assertEquals(500, $movements[0]['unit_cost']);

        $this->actingAsSalesperson();
        $spResponse = $this->getJson("/api/reports/stock-movements?sku_id={$sku->id}")->assertOk();
        $this->assertArrayNotHasKey('unit_cost', $spResponse->json('movements')[0]);
    }

    public function test_stock_movements_report_rejects_imei_tracked_sku(): void
    {
        $this->bootLedger();
        $this->actingAsAdmin();
        $sku = $this->imeiSku();

        $this->getJson("/api/reports/stock-movements?sku_id={$sku->id}")->assertStatus(422);
    }

    public function test_sales_report_breaks_down_by_model_and_color(): void
    {
        $this->bootLedger();
        $this->actingAsAdmin();
        $sku = $this->nonImeiSku();
        $distributor = Distributor::factory()->create();

        $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 5, 'unit_cost' => 500]],
        ])->assertCreated();

        $cash = \App\Domain\Accounting\Models\PaymentMethod::where('name', 'Cash')->firstOrFail();
        $this->postJson('/api/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 3, 'unit_price' => 800]],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => 2400]],
        ])->assertCreated();

        $response = $this->getJson('/api/reports/sales/summary')->assertOk();

        $modelRow = collect($response->json('by_model'))->first();
        $this->assertSame(3, $modelRow['quantity']);
        $this->assertEquals(2400.0, $modelRow['total']);

        $colorRow = collect($response->json('by_color'))->first();
        $this->assertSame(3, $colorRow['quantity']);
        $this->assertEquals(2400.0, $colorRow['total']);
    }

    public function test_sales_report_breaks_down_by_salesperson_and_customer(): void
    {
        $this->bootLedger();
        $admin = $this->actingAsAdmin();
        $sku = $this->nonImeiSku();
        $distributor = Distributor::factory()->create();

        $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 5, 'unit_cost' => 500]],
        ])->assertCreated();

        $customer = Customer::factory()->create(['name' => 'Report Customer']);
        $cash = \App\Domain\Accounting\Models\PaymentMethod::where('name', 'Cash')->firstOrFail();

        $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 2, 'unit_price' => 800]],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => 1600]],
        ])->assertCreated();

        // Walk-in sale (no customer_id) so the "Walk-in" bucket is exercised too.
        $this->postJson('/api/sales', [
            'sale_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 1, 'unit_price' => 800]],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => 800]],
        ])->assertCreated();

        $response = $this->getJson('/api/reports/sales/summary')->assertOk();

        $salespersonRow = collect($response->json('by_salesperson'))->firstWhere('name', $admin->name);
        $this->assertNotNull($salespersonRow);
        $this->assertSame(2, $salespersonRow['count']);
        $this->assertEquals(2400.0, $salespersonRow['total']);

        $customerRow = collect($response->json('by_customer'))->firstWhere('name', 'Report Customer');
        $this->assertSame(1, $customerRow['count']);
        $this->assertEquals(1600.0, $customerRow['total']);

        $walkInRow = collect($response->json('by_customer'))->firstWhere('name', 'Walk-in');
        $this->assertSame(1, $walkInRow['count']);
        $this->assertEquals(800.0, $walkInRow['total']);
    }

    public function test_imei_history_shows_full_lifecycle_and_hides_cost_from_salesperson(): void
    {
        $this->bootLedger();
        $this->actingAsAdmin();
        $sku = $this->imeiSku();
        $distributor = Distributor::factory()->create();

        $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [[
                'product_variant_color_id' => $sku->id,
                'quantity' => 1,
                'unit_cost' => 24000,
                'imeis' => [['imei1' => '400000000000001']],
            ]],
        ])->assertCreated();

        $cash = \App\Domain\Accounting\Models\PaymentMethod::where('name', 'Cash')->firstOrFail();
        $unit = \App\Domain\Inventory\Models\ImeiUnit::where('imei1', '400000000000001')->firstOrFail();
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

        $adminResponse = $this->getJson('/api/reports/imei-history?imei=400000000000001')->assertOk();
        $this->assertSame('sold', $adminResponse->json('unit.status'));
        $this->assertEquals(24000, $adminResponse->json('unit.purchase_cost'));
        $types = collect($adminResponse->json('movements'))->pluck('type');
        $this->assertSame(['purchase', 'sale'], $types->all());

        $this->actingAsSalesperson();
        $spResponse = $this->getJson('/api/reports/imei-history?imei=400000000000001')->assertOk();
        $spResponse->assertJsonMissingPath('unit.purchase_cost');
    }

    public function test_imei_history_returns_404_for_unknown_imei(): void
    {
        $this->bootLedger();
        $this->actingAsAdmin();

        $this->getJson('/api/reports/imei-history?imei=999999999999999')->assertNotFound();
    }
}
