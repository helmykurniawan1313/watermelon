<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up()
{
    Schema::create('installments', function (Blueprint $table) {
        $table->id();
        $table->foreignId('ledger_id')->constrained()->onDelete('cascade');
        $table->string('title'); // e.g., "iPhone 15"
        $table->decimal('total_amount', 15, 2);
        $table->decimal('paid_amount', 15, 2)->default(0);
        $table->decimal('monthly_amount', 15, 2);
        $table->integer('duration_months');
        $table->foreignId('account_id')->constrained('accounts'); // Where the money usually comes from (e.g., BCA)
        $table->foreignId('category_id')->nullable()->constrained('categories'); // E.g., Shopping
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('installments');
    }
};
