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
    Schema::table('installments', function (Blueprint $table) {
        // Adds the missing column, defaults to 0 so it doesn't break old data
        if (!Schema::hasColumn('installments', 'interest_rate')) {
            $table->decimal('interest_rate', 5, 2)->default(0)->after('monthly_amount');
        }
    });
}

public function down(): void
{
    Schema::table('installments', function (Blueprint $table) {
        $table->dropColumn('interest_rate');
    });
}
};
