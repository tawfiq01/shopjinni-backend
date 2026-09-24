<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Same table list as the previous migration (minus `users`, handled
     * separately below since only some users get attached automatically).
     */
    private const TABLES = [
        'branches',
        'brands',
        'product_types',
        'colors',
        'product_models',
        'product_variants',
        'product_variant_colors',
        'chart_of_accounts',
        'journal_entries',
        'journal_lines',
        'distributors',
        'payment_methods',
        'purchase_invoices',
        'purchase_items',
        'purchase_payments',
        'inventory_stocks',
        'imei_units',
        'stock_movements',
        'customers',
        'sales_invoices',
        'sale_items',
        'sale_payments',
        'expense_categories',
        'expenses',
        'sales_returns',
        'sales_return_items',
        'purchase_returns',
        'purchase_return_items',
        'phone_exchanges',
        'stock_transfers',
        'stock_transfer_items',
        'backup_logs',
        'backup_settings',
    ];

    /**
     * Everything that already exists today belongs to one shop — there is
     * no multi-tenant data yet to disambiguate, so every row across every
     * table (and every existing user) is simply attached to this one new
     * company. Any company created from here on goes through
     * CompanyProvisioningService instead.
     *
     * A no-op on a genuinely fresh database (no users yet) — otherwise
     * this unconditionally created a phantom, empty "Fair Telecom" company
     * on every fresh install and every test run (RefreshDatabase migrates
     * from scratch), which is exactly the kind of stray row a real backfill
     * migration should never produce.
     */
    public function up(): void
    {
        if (DB::table('users')->count() === 0) {
            return;
        }

        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Fair Telecom',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (self::TABLES as $table) {
            DB::table($table)->update(['company_id' => $companyId]);
        }

        DB::table('users')->update(['company_id' => $companyId]);
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::table($table)->update(['company_id' => null]);
        }

        DB::table('users')->update(['company_id' => null]);
        DB::table('companies')->where('name', 'Fair Telecom')->delete();
    }
};
