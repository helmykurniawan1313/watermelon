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
    Schema::create('transactions', function (Blueprint $table) {
        $table->id();
        $table->foreignId('ledger_id')->constrained()->cascadeOnDelete();
        $table->foreignId('account_id')->constrained()->onDelete('cascade');
        $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // Tracks who spent the money
        $table->foreignId('category_id')->nullable();
        // Note: Our installment_id was added in a separate migration earlier!
        
        $table->decimal('amount', 15, 2);
        $table->dateTime('date'); // Change this from date to dateTime
        $table->string('title'); // e.g., "Walmart Groceries"
        $table->text('notes')->nullable();
        $table->string('type'); // 'income' or 'expense'
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
