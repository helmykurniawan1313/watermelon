<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE categories MODIFY type VARCHAR(255) NOT NULL DEFAULT 'both'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE categories MODIFY type VARCHAR(255) NOT NULL");
    }
};
