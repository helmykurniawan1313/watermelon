<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_user', function (Blueprint $table) {
            $table->id();
            // cascadeOnDelete means if a user or ledger is deleted, this link is cleanly removed
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('member'); // Can be 'owner' or 'member'
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_user');
    }
};
