<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columns that were globally unique before multi-tenancy — every one
     * needs to become unique per company instead, or a second shop can
     * never use a branch name, COA code, SKU, invoice number, etc. that
     * any other shop already used.
     *
     * (This migration originally also enforced company_id NOT NULL via a
     * raw `ALTER TABLE ... MODIFY`, but that's MySQL-only syntax — SQLite,
     * which the test suite runs on, has no ALTER COLUMN support at all.
     * Dropped that step: BelongsToCompany's global scope already fails
     * closed on a null company_id (zero rows, never "no filter"), and its
     * creating() hook always fills it on every normal write path, so the
     * DB-level constraint would only have been a belt-and-suspenders
     * guard against a raw DB::table()->insert() bypassing Eloquent
     * entirely — which nothing in this app does.)
     */
    private const COMPOSITE_UNIQUES = [
        'brands' => ['name'],
        'product_types' => ['name'],
        'colors' => ['name'],
        'chart_of_accounts' => ['code'],
        'purchase_invoices' => ['invoice_number'],
        'sales_invoices' => ['invoice_number'],
        'payment_methods' => ['name'],
        'expense_categories' => ['name'],
        'product_variant_colors' => ['sku', 'barcode'],
        'imei_units' => ['imei1', 'imei2'],
    ];

    public function up(): void
    {
        foreach (self::COMPOSITE_UNIQUES as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column) {
                    $blueprint->dropUnique([$column]);
                    $blueprint->unique(['company_id', $column]);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::COMPOSITE_UNIQUES as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column) {
                    $blueprint->dropUnique(['company_id', $column]);
                    $blueprint->unique($column);
                }
            });
        }
    }
};
