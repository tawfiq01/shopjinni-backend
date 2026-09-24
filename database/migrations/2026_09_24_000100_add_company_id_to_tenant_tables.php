<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every tenant-owned table, plus the framework's own `users` table.
     * `company_id` starts nullable on all of them so the next migration can
     * backfill existing rows before a later migration enforces NOT NULL
     * (users.company_id stays nullable forever — it's how a signed-up-but-
     * not-yet-onboarded account is represented).
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

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->boolean('is_super_admin')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('company_id');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn('is_super_admin');
        });
    }
};
