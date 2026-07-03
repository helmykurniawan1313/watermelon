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
    Schema::create('accounts', function (Blueprint $table) {
        $table->id();
        // This line requires the 'ledgers' table to ALREADY exist
        $table->foreignId('ledger_id')->constrained()->onDelete('cascade');
        $table->string('name');
        $table->decimal('balance', 12, 2)->default(0);
        $table->string('icon')->default('💳');
        $table->string('color')->default('#3b82f6');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
