<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL-only syntax. SQLite (used by the test suite) can't MODIFY columns;
        // the app always sends a type, so the default isn't needed there.
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement("ALTER TABLE categories MODIFY type VARCHAR(255) NOT NULL DEFAULT 'both'");
    }

    public function down(): void
    {
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        DB::statement("ALTER TABLE categories MODIFY type VARCHAR(255) NOT NULL");
    }
};
