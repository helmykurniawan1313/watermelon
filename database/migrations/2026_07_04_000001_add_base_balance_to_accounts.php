<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `base_balance` is the account's starting point before any transactions.
     * The old `balance` column used to be mutated directly on every transaction;
     * going forward it is computed live as base_balance + sum(non-future transactions),
     * so future-dated transactions no longer affect the current balance.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->decimal('base_balance', 12, 2)->default(0)->after('balance');
        });

        // Backfill: base_balance = current stored balance minus the net effect of
        // every transaction that has already been applied to it (all of them, since
        // the old logic applied every transaction regardless of date).
        $accounts = DB::table('accounts')->get();

        foreach ($accounts as $account) {
            $net = DB::table('transactions')
                ->where('account_id', $account->id)
                ->selectRaw("SUM(CASE WHEN type = 'income' THEN amount WHEN type = 'expense' THEN -amount ELSE 0 END) as net")
                ->value('net') ?? 0;

            $netAsSource = DB::table('transactions')
                ->where('account_id', $account->id)
                ->where('type', 'transfer')
                ->sum('amount');

            $netAsDest = DB::table('transactions')
                ->where('to_account_id', $account->id)
                ->where('type', 'transfer')
                ->sum('amount');

            $transferNet = $netAsDest - $netAsSource;

            $totalNet = $net + $transferNet;

            DB::table('accounts')
                ->where('id', $account->id)
                ->update(['base_balance' => $account->balance - $totalNet]);
        }
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('base_balance');
        });
    }
};
