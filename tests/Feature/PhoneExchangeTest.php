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
use App\Domain\Purchasing\Models\Distributor;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PhoneExchangeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private function actingAsAdmin(): User
    {
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        $this->branch = Branch::firstOrCreate(['name' => 'Main Branch'], ['is_main' => true]);

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

    private function imeiSku(): ProductVariantColor
    {
        $type = ProductType::factory()->create(['imei_tracking_default' => true]);

        return ProductVariantColor::factory()->create([
            'product_variant_id' => ProductVariant::factory()->create([
                'product_model_id' => ProductModel::factory()->create(['product_type_id' => $type->id])->id,
            ])->id,
        ]);
    }

    private function stockNewPhone(ProductVariantColor $sku, string $imei, float $cost): void
    {
        $distributor = Distributor::factory()->create();
        $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [[
                'product_variant_color_id' => $sku->id,
                'quantity' => 1,
                'unit_cost' => $cost,
                'imeis' => [['imei1' => $imei]],
            ]],
        ])->assertCreated();
    }

    public function test_exchange_with_customer_paying_the_difference(): void
    {
        $this->actingAsAdmin();
        $oldSku = $this->imeiSku();
        $newSku = $this->imeiSku();
        $this->stockNewPhone($newSku, '100000000000001', 20000);
        $newUnit = ImeiUnit::where('imei1', '100000000000001')->firstOrFail();
        $cash = PaymentMethod::where('name', 'Cash')->firstOrFail();

        $response = $this->postJson('/api/phone-exchanges', [
            'exchange_date' => now()->toDateString(),
            'old_phone' => [
                'product_variant_color_id' => $oldSku->id,
                'exchange_value' => 15000,
                'imei1' => '200000000000002',
            ],
            'new_phone' => [
                'product_variant_color_id' => $newSku->id,
                'imei_unit_id' => $newUnit->id,
                'unit_price' => 30000,
            ],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => 15000]],
        ])->assertCreated();

        $this->assertEquals(15000.0, $response->json('data.price_difference'));

        // Old phone is now in stock, sellable.
        $oldUnit = ImeiUnit::where('imei1', '200000000000002')->firstOrFail();
        $this->assertSame('in_stock', $oldUnit->status);

        // New phone left stock.
        $this->assertSame('sold', $newUnit->fresh()->status);

        $revenue = ChartOfAccount::where('code', '4000')->firstOrFail();
        $inventory = ChartOfAccount::where('code', '1200')->firstOrFail();
        $cashAccount = ChartOfAccount::where('code', '1000')->firstOrFail();

        $this->assertEquals(30000.0, $revenue->balance());
        $this->assertEquals(15000.0, $cashAccount->balance());
        // +20000 (original purchase) +15000 (old phone in) -20000 (COGS of new phone sold) = 15000
        $this->assertEquals(15000.0, $inventory->balance());
    }

    public function test_exchange_leaving_a_due_requires_a_customer(): void
    {
        $this->actingAsAdmin();
        $oldSku = $this->imeiSku();
        $newSku = $this->imeiSku();
        $this->stockNewPhone($newSku, '100000000000003', 20000);
        $newUnit = ImeiUnit::where('imei1', '100000000000003')->firstOrFail();

        $this->postJson('/api/phone-exchanges', [
            'exchange_date' => now()->toDateString(),
            'old_phone' => ['product_variant_color_id' => $oldSku->id, 'exchange_value' => 15000, 'imei1' => '200000000000004'],
            'new_phone' => ['product_variant_color_id' => $newSku->id, 'imei_unit_id' => $newUnit->id, 'unit_price' => 30000],
        ])->assertUnprocessable();
    }

    public function test_exchange_where_old_phone_is_worth_more_requires_refund_method(): void
    {
        $this->actingAsAdmin();
        $oldSku = $this->imeiSku();
        $newSku = $this->imeiSku();
        $this->stockNewPhone($newSku, '100000000000005', 10000);
        $newUnit = ImeiUnit::where('imei1', '100000000000005')->firstOrFail();

        $this->postJson('/api/phone-exchanges', [
            'exchange_date' => now()->toDateString(),
            'old_phone' => ['product_variant_color_id' => $oldSku->id, 'exchange_value' => 20000, 'imei1' => '200000000000006'],
            'new_phone' => ['product_variant_color_id' => $newSku->id, 'imei_unit_id' => $newUnit->id, 'unit_price' => 15000],
        ])->assertUnprocessable();
    }

    public function test_exchange_with_refund_when_old_phone_is_worth_more(): void
    {
        $this->actingAsAdmin();
        $oldSku = $this->imeiSku();
        $newSku = $this->imeiSku();
        $this->stockNewPhone($newSku, '100000000000007', 10000);
        $newUnit = ImeiUnit::where('imei1', '100000000000007')->firstOrFail();
        $cash = PaymentMethod::where('name', 'Cash')->firstOrFail();

        $response = $this->postJson('/api/phone-exchanges', [
            'exchange_date' => now()->toDateString(),
            'old_phone' => ['product_variant_color_id' => $oldSku->id, 'exchange_value' => 20000, 'imei1' => '200000000000008'],
            'new_phone' => ['product_variant_color_id' => $newSku->id, 'imei_unit_id' => $newUnit->id, 'unit_price' => 15000],
            'refund_method_id' => $cash->id,
        ])->assertCreated();

        $this->assertEquals(-5000.0, $response->json('data.price_difference'));

        $cashAccount = ChartOfAccount::where('code', '1000')->firstOrFail();
        $this->assertEquals(-5000.0, $cashAccount->balance()); // shop paid customer 5000 back
    }

    public function test_exchange_updates_customer_due_when_partially_paid(): void
    {
        $this->actingAsAdmin();
        $oldSku = $this->imeiSku();
        $newSku = $this->imeiSku();
        $this->stockNewPhone($newSku, '100000000000009', 20000);
        $newUnit = ImeiUnit::where('imei1', '100000000000009')->firstOrFail();
        $customer = Customer::factory()->create();
        $cash = PaymentMethod::where('name', 'Cash')->firstOrFail();

        $this->postJson('/api/phone-exchanges', [
            'customer_id' => $customer->id,
            'exchange_date' => now()->toDateString(),
            'old_phone' => ['product_variant_color_id' => $oldSku->id, 'exchange_value' => 15000, 'imei1' => '200000000000010'],
            'new_phone' => ['product_variant_color_id' => $newSku->id, 'imei_unit_id' => $newUnit->id, 'unit_price' => 30000],
            'payments' => [['payment_method_id' => $cash->id, 'amount' => 10000]],
        ])->assertCreated();

        $this->assertEquals(5000.0, $customer->fresh()->currentBalance());
    }
}
