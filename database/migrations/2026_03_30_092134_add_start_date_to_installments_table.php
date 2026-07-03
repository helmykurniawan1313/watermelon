<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
  public function up(): void
{
    // WE USE 'table' HERE BECAUSE THE TABLE ALREADY EXISTS
    Schema::table('installments', function (Blueprint $table) {
        
        // This adds the missing columns to your existing table
        if (!Schema::hasColumn('installments', 'duration_months')) {
            $table->integer('duration_months')->after('title');
        }
        
        if (!Schema::hasColumn('installments', 'base_price')) {
            $table->decimal('base_price', 15, 2)->after('duration_months');
        }

        if (!Schema::hasColumn('installments', 'monthly_amount')) {
            $table->decimal('monthly_amount', 15, 2)->after('base_price');
        }

        if (!Schema::hasColumn('installments', 'start_date')) {
            $table->date('start_date')->after('monthly_amount');
        }
    });
}

public function down(): void
{
    Schema::table('installments', function (Blueprint $table) {
        $table->dropColumn(['duration_months', 'base_price', 'monthly_amount', 'start_date']);
    });
}
};
