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
use App\Domain\Purchasing\Models\Distributor;
use App\Domain\Purchasing\Models\PurchaseInvoice;
use App\Models\User;
use Database\Seeders\ChartOfAccountSeeder;
use Database\Seeders\PaymentMethodSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DistributorTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        Permission::firstOrCreate(['name' => 'distributors.manage', 'guard_name' => 'web']);

        $company = $this->createCompany('Test Company');
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->assignCompanyRole($company, $user, 'Admin', ['distributors.manage']);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    private function actingAsAdminWhoCanPurchase(): User
    {
        Permission::firstOrCreate(['name' => 'distributors.manage', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'purchases.manage', 'guard_name' => 'web']);

        $company = $this->createCompany('Test Company');
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->assignCompanyRole($company, $user, 'Admin', ['distributors.manage', 'purchases.manage']);
        $this->actingAs($user, 'sanctum');

        $this->seed(ChartOfAccountSeeder::class);
        $this->seed(PaymentMethodSeeder::class);
        $branch = Branch::firstOrCreate(['name' => 'Main Branch'], ['is_main' => true]);
        $user->update(['branch_id' => $branch->id]);

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

    public function test_creating_a_distributor_with_opening_balance_posts_a_balanced_entry(): void
    {
        $this->actingAsAdmin();
        $this->seed(ChartOfAccountSeeder::class);

        $response = $this->postJson('/api/distributors', [
            'name' => 'ABC Mobile Distributors',
            'mobile' => '01711111111',
            'opening_balance' => 25000,
        ])->assertCreated();

        $distributor = Distributor::findOrFail($response->json('data.id'));
        $this->assertSame(25000.0, $distributor->currentBalance());

        $payable = ChartOfAccount::where('code', '2000')->firstOrFail();
        $this->assertSame(25000.0, $payable->balance());
    }

    public function test_distributor_with_zero_opening_balance_posts_no_entry(): void
    {
        $this->actingAsAdmin();
        $this->seed(ChartOfAccountSeeder::class);

        $response = $this->postJson('/api/distributors', [
            'name' => 'No Balance Traders',
            'mobile' => '01822222222',
        ])->assertCreated();

        $this->assertEquals(0.0, $response->json('data.current_balance'));
    }

    public function test_distributor_cannot_be_deleted_once_it_has_ledger_history(): void
    {
        $this->actingAsAdmin();
        $this->seed(ChartOfAccountSeeder::class);

        $id = $this->postJson('/api/distributors', [
            'name' => 'Has History Ltd',
            'mobile' => '01933333333',
            'opening_balance' => 5000,
        ])->assertCreated()->json('data.id');

        $this->deleteJson("/api/distributors/{$id}")->assertStatus(409);
    }

    public function test_user_without_permission_cannot_create_a_distributor(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/distributors', [
            'name' => 'Blocked Co',
            'mobile' => '01999999999',
        ])->assertForbidden();
    }

    public function test_paying_a_distributor_reduces_the_due_and_allocates_to_the_oldest_invoice(): void
    {
        $this->actingAsAdminWhoCanPurchase();
        $distributor = Distributor::factory()->create();
        $sku = $this->nonImeiSku();

        $invoice = PurchaseInvoice::find(
            $this->postJson('/api/purchases', [
                'distributor_id' => $distributor->id,
                'purchase_date' => now()->toDateString(),
                'items' => [
                    ['product_variant_color_id' => $sku->id, 'quantity' => 10, 'unit_cost' => 500],
                ],
            ])->assertCreated()->json('data.id')
        );
        $this->assertSame(5000.0, $distributor->fresh()->currentBalance());

        $cash = PaymentMethod::where('name', 'Cash')->firstOrFail();
        $this->postJson("/api/distributors/{$distributor->id}/payments", [
            'payment_method_id' => $cash->id,
            'amount' => 2000,
        ])->assertOk()
            ->assertJsonPath('distributor.current_balance', 3000);

        $this->assertSame(3000.0, $distributor->fresh()->currentBalance());

        $invoice->refresh();
        $this->assertEquals(2000.0, (float) $invoice->paid_amount);
        $this->assertEquals(3000.0, (float) $invoice->due_amount);

        // Cash is a debit-normal asset account; paying cash out credits it,
        // so its balance moves negative here since nothing funded it first.
        $cashAccount = ChartOfAccount::find($cash->chart_of_account_id);
        $this->assertSame(-2000.0, $cashAccount->balance());
    }

    public function test_paying_more_than_the_outstanding_due_is_rejected(): void
    {
        $this->actingAsAdminWhoCanPurchase();
        $distributor = Distributor::factory()->create();
        $sku = $this->nonImeiSku();

        $this->postJson('/api/purchases', [
            'distributor_id' => $distributor->id,
            'purchase_date' => now()->toDateString(),
            'items' => [
                ['product_variant_color_id' => $sku->id, 'quantity' => 1, 'unit_cost' => 1000],
            ],
        ])->assertCreated();

        $cash = PaymentMethod::where('name', 'Cash')->firstOrFail();
        $this->postJson("/api/distributors/{$distributor->id}/payments", [
            'payment_method_id' => $cash->id,
            'amount' => 1000.01,
        ])->assertStatus(422);

        $this->assertSame(1000.0, $distributor->fresh()->currentBalance());
    }

    public function test_paying_a_distributor_with_no_outstanding_due_is_rejected(): void
    {
        $this->actingAsAdminWhoCanPurchase();
        $distributor = Distributor::factory()->create();

        $cash = PaymentMethod::where('name', 'Cash')->firstOrFail();
        $this->postJson("/api/distributors/{$distributor->id}/payments", [
            'payment_method_id' => $cash->id,
            'amount' => 100,
        ])->assertStatus(422);
    }

    public function test_paying_a_distributor_whose_due_is_from_opening_balance_still_updates_the_ledger(): void
    {
        $this->actingAsAdminWhoCanPurchase();

        $distributorId = $this->postJson('/api/distributors', [
            'name' => 'Opening Balance Only Co',
            'mobile' => '01755555555',
            'opening_balance' => 4000,
        ])->assertCreated()->json('data.id');
        $distributor = Distributor::findOrFail($distributorId);

        $cash = PaymentMethod::where('name', 'Cash')->firstOrFail();
        $this->postJson("/api/distributors/{$distributor->id}/payments", [
            'payment_method_id' => $cash->id,
            'amount' => 1500,
        ])->assertOk()
            ->assertJsonPath('distributor.current_balance', 2500);

        $this->assertSame(2500.0, $distributor->fresh()->currentBalance());
    }

    public function test_user_without_permission_cannot_pay_a_distributor(): void
    {
        $this->actingAsAdminWhoCanPurchase();
        $distributor = Distributor::factory()->create();
        $cash = PaymentMethod::where('name', 'Cash')->firstOrFail();

        $plainUser = User::factory()->create(['company_id' => $distributor->company_id]);
        $this->actingAs($plainUser, 'sanctum');

        $this->postJson("/api/distributors/{$distributor->id}/payments", [
            'payment_method_id' => $cash->id,
            'amount' => 100,
        ])->assertForbidden();
    }
}
