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
    Schema::create('categories', function (Blueprint $table) {
        $table->id();
        $table->foreignId('ledger_id')->constrained()->cascadeOnDelete(); // Custom per workspace
        $table->string('name'); // e.g., "Groceries", "Salary"
        $table->string('type'); // 'income' or 'expense'
        $table->string('icon')->nullable();
        $table->string('color')->nullable();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
