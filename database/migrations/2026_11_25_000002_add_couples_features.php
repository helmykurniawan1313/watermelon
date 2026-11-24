<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who an account belongs to inside a shared household (null = shared/joint account).
        Schema::table('accounts', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('ledger_id')->constrained()->nullOnDelete();
        });

        // Activity feed: who added / edited / deleted what, per household.
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 20);         // created | updated | deleted | paid | joined | left
            $table->string('subject_type', 20);   // transaction | account | category | installment | member
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('summary', 255);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['ledger_id', 'created_at']);
        });

        // Partner invites: a short code the inviter shares privately; single use, expires.
        Schema::create('ledger_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inviter_id')->constrained('users')->cascadeOnDelete();
            $table->string('code', 16)->unique();
            $table->timestamp('expires_at');
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_invitations');
        Schema::dropIfExists('activity_logs');
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
