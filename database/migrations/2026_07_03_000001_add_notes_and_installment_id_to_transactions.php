<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'notes')) {
                $table->text('notes')->nullable()->after('title');
            }
            if (!Schema::hasColumn('transactions', 'installment_id')) {
                $table->foreignId('installment_id')->nullable()->after('to_account_id')->constrained('installments')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('installment_id');
            $table->dropColumn('notes');
        });
    }
};
