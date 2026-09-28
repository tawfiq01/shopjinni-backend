<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('owner_name')->nullable()->after('name');
            $table->string('district')->nullable()->after('address');
            $table->string('country')->nullable()->default('Bangladesh')->after('district');
            $table->string('currency')->nullable()->default('BDT')->after('country');
            $table->string('timezone')->nullable()->default('Asia/Dhaka')->after('currency');
            $table->timestamp('setup_wizard_completed_at')->nullable()->after('is_active');
        });

        // A shop that's already been in daily use before this migration
        // shipped should never suddenly get pushed into a "let's set up
        // your shop" wizard — only companies created AFTER this migration
        // (setup_wizard_completed_at stays null by the column default)
        // are meant to see it.
        DB::table('companies')->whereNull('setup_wizard_completed_at')->update([
            'setup_wizard_completed_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['owner_name', 'district', 'country', 'currency', 'timezone', 'setup_wizard_completed_at']);
        });
    }
};
