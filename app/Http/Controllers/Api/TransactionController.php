<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\Account;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB; // Crucial for data integrity

class TransactionController extends Controller
{
    /**
     * Fetch all transactions for a specific ledger.
     */
    public function index(Request $request, Ledger $ledger)
    {
        $this->authorizeLedger($ledger);

        $transactions = $ledger->transactions()
            ->with(['category', 'user:id,name', 'account', 'toAccount']) // Added toAccount for transfers
            ->orderBy('date', 'desc')
            ->get();

        return response()->json($transactions);
    }

    /**
     * Log a new transaction (Income, Expense, or Transfer).
     */
    public function store(Request $request, Ledger $ledger)
    {
        $this->authorizeLedger($ledger);

        $validated = $this->normalize($request->validate($this->rules($ledger->id)));

        return DB::transaction(function () use ($request, $ledger, $validated) {
            $transaction = $ledger->transactions()->create([
                'user_id'       => $request->user()->id,
                'account_id'    => $validated['account_id'],
                'to_account_id' => $validated['to_account_id'] ?? null,
                'category_id'   => $validated['category_id'] ?? null,
                'amount'        => $validated['amount'],
                'date'          => $validated['date'],
                'title'         => $validated['title'],
                'notes'         => $validated['notes'] ?? null,
                'type'          => $validated['type'],
            ]);

            ActivityLog::record($ledger->id, 'created', 'transaction', $transaction->id, $this->describe('added', $transaction),
                ['type' => $transaction->type, 'amount' => (float) $transaction->amount]);

            return response()->json([
                'message' => 'Transaction logged successfully!',
                'transaction' => $transaction->load('category', 'account', 'toAccount')
            ], 201);
        });
    }

    /**
     * Update a transaction and recalculate balances.
     */
    public function update(Request $request, Transaction $transaction)
    {
        $this->authorizeLedger($transaction->ledger);

        $validated = $this->normalize($request->validate($this->rules($transaction->ledger_id)));

        return DB::transaction(function () use ($transaction, $validated) {
            $oldAmount = $transaction->amount;
            $installment = $transaction->installment_id ? $transaction->installment()->lockForUpdate()->first() : null;

            $before = $this->describe('', $transaction);
            $transaction->update($validated);
            ActivityLog::record($transaction->ledger_id, 'updated', 'transaction', $transaction->id,
                $this->describe('edited', $transaction), ['before' => $before]);

            if ($installment) {
                $installment->paid_amount = max(0, $installment->paid_amount - $oldAmount + $transaction->amount);
                $installment->save();
            }

            return response()->json($transaction->load('category', 'account', 'toAccount'));
        });
    }

    /**
     * Delete a transaction and revert balances.
     */
    public function destroy(Request $request, Transaction $transaction)
    {
        $this->authorizeLedger($transaction->ledger);

        return DB::transaction(function () use ($transaction) {
            // If this was an installment payment, roll back its progress too.
            if ($transaction->installment_id) {
                $installment = $transaction->installment;
                if ($installment) {
                    $installment->paid_amount = max(0, $installment->paid_amount - $transaction->amount);
                    $installment->save();
                }
            }

            ActivityLog::record($transaction->ledger_id, 'deleted', 'transaction', $transaction->id, $this->describe('deleted', $transaction));
            $transaction->delete();

            return response()->json(['message' => 'Transaction deleted successfully']);
        });
    }

    private function describe(string $verb, Transaction $tx): string
    {
        $kind = in_array($tx->type, ['expense', 'income', 'transfer'], true) ? $tx->type : 'transaction';
        return trim("{$verb} {$kind} \"{$tx->title}\" · " . ActivityLog::money($tx->amount));
    }

    /**
     * Shared validation for creating and editing a transaction.
     */
    private function rules(int $ledgerId): array
    {
        return [
            'title'         => 'required|string|max:255',
            'amount'        => 'required|numeric|gt:0',
            'type'          => 'required|in:income,expense,transfer',
            'date'          => 'required|date',
            'account_id'    => 'required|exists:accounts,id,ledger_id,' . $ledgerId,
            // A transfer must go to a different account in the same ledger.
            'to_account_id' => 'required_if:type,transfer|nullable|different:account_id|exists:accounts,id,ledger_id,' . $ledgerId,
            'category_id'   => 'required_unless:type,transfer|nullable|exists:categories,id,ledger_id,' . $ledgerId,
            'notes'         => 'nullable|string',
        ];
    }

    /**
     * Drop fields that don't apply to the type, so switching a transfer to an
     * expense (or back) doesn't leave a stale destination account or category behind.
     */
    private function normalize(array $validated): array
    {
        if ($validated['type'] === 'transfer') {
            $validated['category_id'] = null;
        } else {
            $validated['to_account_id'] = null;
        }

        return $validated;
    }

    public function activeMonths(Request $request, Ledger $ledger)
    {
        $this->authorizeLedger($ledger);

        // Every unique Month/Year combo in the ledger's transactions
        $monthSql = DB::getDriverName() === 'sqlite'
            ? "CAST(strftime('%m', date) AS INTEGER) as month, CAST(strftime('%Y', date) AS INTEGER) as year"
            : 'MONTH(date) as month, YEAR(date) as year';

        return $ledger->transactions()
            ->selectRaw($monthSql)
            ->distinct()
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->get();
    }

    public function netWorthTrend(Request $request, Ledger $ledger)
    {
        $this->authorizeLedger($ledger);
        $ledgerId = $ledger->id;

        // 1. Get current total net worth right now (must load models, not a raw SQL sum,
        // since `balance` is now a computed accessor that excludes future-dated transactions).
        $currentNetWorth = \App\Models\Account::withComputedBalances(
            \App\Models\Account::where('ledger_id', $ledgerId)->get()
        )->sum('balance');

    // 2. Get all income and expenses from the last 30 days (excluding future-dated transactions,
    // which don't affect net worth until their date arrives).
    $startDate = now()->subDays(30)->startOfDay();
    $transactions = \App\Models\Transaction::where('ledger_id', $ledgerId)
        ->where('date', '>=', $startDate)
        ->where('date', '<=', now())
        ->whereIn('type', ['income', 'expense']) // Transfers don't change net worth!
        ->get();

    // 3. Group the money spent/earned by date
    $dailyChanges = [];
    foreach ($transactions as $tx) {
        $date = \Carbon\Carbon::parse($tx->date)->format('Y-m-d');
        if (!isset($dailyChanges[$date])) {
            $dailyChanges[$date] = ['income' => 0, 'expense' => 0];
        }
        if ($tx->type === 'income') $dailyChanges[$date]['income'] += $tx->amount;
        if ($tx->type === 'expense') $dailyChanges[$date]['expense'] += $tx->amount;
    }

    // 4. Walk backwards 30 days to calculate historical net worth
    $trend = [];
    $runningNetWorth = $currentNetWorth;

    for ($i = 0; $i < 30; $i++) {
        $dateObj = now()->subDays($i);
        $dateStr = $dateObj->format('Y-m-d');
        
        $trend[] = [
            'date' => $dateObj->format('M d'), // e.g., "Apr 14"
            'net_worth' => $runningNetWorth
        ];

        // To calculate yesterday's net worth, we UNDO today's actions.
        // If I spent money today, it means I had MORE money yesterday (+ expense)
        // If I earned money today, it means I had LESS money yesterday (- income)
        if (isset($dailyChanges[$dateStr])) {
            $runningNetWorth = $runningNetWorth - $dailyChanges[$dateStr]['income'] + $dailyChanges[$dateStr]['expense'];
        }
    }

    // Return it chronologically (oldest to newest)
    return response()->json(array_reverse($trend));
}
}