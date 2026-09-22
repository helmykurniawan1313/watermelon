<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    // These MUST be listed here or Laravel will ignore them during a 'create' call
    protected $fillable = [
        'ledger_id',
        'name',
        'balance',
        'base_balance',
        'icon',
        'color'
    ];

    // Loaded internally for authorization checks; never serialize the parent ledger (and its user list) back to clients.
    protected $hidden = ['ledger'];

    public function ledger()
    {
        return $this->belongsTo(Ledger::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Effective balance = base_balance + net effect of every transaction dated now or earlier.
     * Future-dated transactions are excluded so they don't move the balance before their date arrives.
     */
    public function getBalanceAttribute()
    {
        if (array_key_exists('_computed_balance', $this->attributes)) {
            return $this->attributes['_computed_balance'];
        }

        $now = now();

        $net = $this->transactions()
            ->where('date', '<=', $now)
            ->selectRaw("SUM(CASE
                WHEN type = 'income' THEN amount
                WHEN type = 'expense' THEN -amount
                WHEN type = 'transfer' THEN -amount
                ELSE 0
            END) as net")
            ->value('net') ?? 0;

        $incomingTransfers = Transaction::where('to_account_id', $this->id)
            ->where('type', 'transfer')
            ->where('date', '<=', $now)
            ->sum('amount');

        return (float) $this->base_balance + (float) $net + (float) $incomingTransfers;
    }

    /**
     * Attach computed balances to a collection of accounts using 2 grouped
     * queries total instead of 2 queries per account (avoids N+1 when
     * listing/summing many accounts at once, e.g. dashboard/reports).
     */
    public static function withComputedBalances($accounts)
    {
        $accounts = $accounts instanceof \Illuminate\Support\Collection ? $accounts : collect($accounts);
        if ($accounts->isEmpty()) {
            return $accounts;
        }

        $now = now();
        $ids = $accounts->pluck('id');

        $netByAccount = Transaction::whereIn('account_id', $ids)
            ->where('date', '<=', $now)
            ->selectRaw("account_id, SUM(CASE
                WHEN type = 'income' THEN amount
                WHEN type = 'expense' THEN -amount
                WHEN type = 'transfer' THEN -amount
                ELSE 0
            END) as net")
            ->groupBy('account_id')
            ->pluck('net', 'account_id');

        $incomingByAccount = Transaction::whereIn('to_account_id', $ids)
            ->where('type', 'transfer')
            ->where('date', '<=', $now)
            ->selectRaw('to_account_id, SUM(amount) as total')
            ->groupBy('to_account_id')
            ->pluck('total', 'to_account_id');

        foreach ($accounts as $account) {
            $net = (float) ($netByAccount[$account->id] ?? 0);
            $incoming = (float) ($incomingByAccount[$account->id] ?? 0);
            $account->attributes['_computed_balance'] = (float) $account->base_balance + $net + $incoming;
        }

        return $accounts;
    }
}