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
        Schema::create('user_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // gemini | groq | openrouter
            $table->string('label')->nullable();
            $table->text('api_key'); // encrypted at rest via the model cast
            $table->string('model')->nullable(); // optional per-key model override
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(0); // lower tries first
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'provider', 'is_active', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_api_keys');
    }
};
