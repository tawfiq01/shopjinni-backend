<?php

namespace Tests\Feature;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\ProductModel;
use App\Domain\Catalog\Models\ProductType;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\ProductVariantColor;
use App\Domain\Companies\Models\Company;
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

class StockTransferTest extends TestCase
{
    use RefreshDatabase;

    private Branch $mainBranch;

    private Branch $secondBranch;

    private function actingAsAdmin(): User
    {
        foreach (['purchases.manage', 'stock.transfer'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $company = $this->createCompany('Test Company');
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->assignCompanyRole($company, $user, 'Admin', ['purchases.manage', 'stock.transfer']);
        $this->actingAs($user, 'sanctum');

        // Seeded (and the branches created) only now, after actingAs() —
        // every one of these belongs to a tenant model, whose
        // BelongsToCompany scope resolves the company from the now-
        // authenticated user.
        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        $this->mainBranch = Branch::firstOrCreate(['name' => 'Main Branch'], ['is_main' => true]);
        $this->secondBranch = Branch::factory()->create(['name' => 'Second Branch']);
        $user->update(['branch_id' => $this->mainBranch->id]);

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

    public function test_transferring_quantity_moves_stock_between_branches(): void
    {
        $this->actingAsAdmin();
        $sku = $this->nonImeiSku();
        $this->purchase($sku, 5, 500);

        $this->postJson('/api/stock-transfers', [
            'from_branch_id' => $this->mainBranch->id,
            'to_branch_id' => $this->secondBranch->id,
            'transfer_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 2]],
        ])->assertCreated();

        $fromStock = InventoryStock::where('branch_id', $this->mainBranch->id)
            ->where('product_variant_color_id', $sku->id)->first();
        $toStock = InventoryStock::where('branch_id', $this->secondBranch->id)
            ->where('product_variant_color_id', $sku->id)->first();

        $this->assertSame(3, $fromStock->quantity);
        $this->assertSame(2, $toStock->quantity);
    }

    public function test_transferring_an_imei_unit_moves_its_branch(): void
    {
        $this->actingAsAdmin();
        $sku = $this->imeiSku();
        $this->purchase($sku, 1, 24000, [['imei1' => '300000000000001']]);
        $unit = ImeiUnit::where('imei1', '300000000000001')->firstOrFail();
        $this->assertSame($this->mainBranch->id, $unit->branch_id);

        $this->postJson('/api/stock-transfers', [
            'from_branch_id' => $this->mainBranch->id,
            'to_branch_id' => $this->secondBranch->id,
            'transfer_date' => now()->toDateString(),
            'items' => [[
                'product_variant_color_id' => $sku->id,
                'quantity' => 1,
                'imei_unit_id' => $unit->id,
            ]],
        ])->assertCreated();

        $this->assertSame($this->secondBranch->id, $unit->fresh()->branch_id);
        $this->assertSame('in_stock', $unit->fresh()->status);
    }

    public function test_cannot_transfer_more_than_available_at_source_branch(): void
    {
        $this->actingAsAdmin();
        $sku = $this->nonImeiSku();
        $this->purchase($sku, 2, 500);

        $this->postJson('/api/stock-transfers', [
            'from_branch_id' => $this->mainBranch->id,
            'to_branch_id' => $this->secondBranch->id,
            'transfer_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 5]],
        ])->assertUnprocessable();
    }

    public function test_source_and_destination_branch_must_differ(): void
    {
        $this->actingAsAdmin();
        $sku = $this->nonImeiSku();
        $this->purchase($sku, 5, 500);

        $this->postJson('/api/stock-transfers', [
            'from_branch_id' => $this->mainBranch->id,
            'to_branch_id' => $this->mainBranch->id,
            'transfer_date' => now()->toDateString(),
            'items' => [['product_variant_color_id' => $sku->id, 'quantity' => 1]],
        ])->assertUnprocessable();
    }

    public function test_transferred_imei_unit_can_be_sold_from_the_new_branch(): void
    {
        $this->actingAsAdmin();
        Permission::firstOrCreate(['name' => 'pos.sell', 'guard_name' => 'web']);
        Role::findByName('Admin', 'web')->givePermissionTo('pos.sell');

        $sku = $this->imeiSku();
        $this->purchase($sku, 1, 24000, [['imei1' => '300000000000002']]);
        $unit = ImeiUnit::where('imei1', '300000000000002')->firstOrFail();

        $this->postJson('/api/stock-transfers', [
            'from_branch_id' => $this->mainBranch->id,
            'to_branch_id' => $this->secondBranch->id,
            'transfer_date' => now()->toDateString(),
            'items' => [[
                'product_variant_color_id' => $sku->id,
                'quantity' => 1,
                'imei_unit_id' => $unit->id,
            ]],
        ])->assertCreated();

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

        $this->assertSame('sold', $unit->fresh()->status);
    }
}
