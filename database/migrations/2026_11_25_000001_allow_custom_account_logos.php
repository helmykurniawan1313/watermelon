<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * accounts.icon now holds the chosen logo: "auto", "badge", "logo:<brand>",
     * or a small uploaded image as a data URL (resized in the app to ~10 KB),
     * which doesn't fit in VARCHAR(255).
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->text('icon')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('icon')->default('💳')->change();
        });
    }
};
