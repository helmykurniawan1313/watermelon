<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\Account;
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
            ->with(['category', 'user', 'account', 'toAccount']) // Added toAccount for transfers
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

        $validated = $request->validate([
            'title'         => 'required|string|max:255',
            'amount'        => 'required|numeric|min:0',
            'type'          => 'required|in:income,expense,transfer',
            'date'          => 'required',
            'account_id'    => 'required|exists:accounts,id,ledger_id,' . $ledger->id,
            'to_account_id' => 'required_if:type,transfer|nullable|exists:accounts,id,ledger_id,' . $ledger->id,
            'category_id'   => 'required_unless:type,transfer|nullable|exists:categories,id,ledger_id,' . $ledger->id,
            'notes'         => 'nullable|string',
        ]);

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

        $ledgerId = $transaction->ledger_id;

        $validated = $request->validate([
            'title'         => 'required|string',
            'amount'        => 'required|numeric',
            'type'          => 'required|in:income,expense,transfer',
            'account_id'    => 'required|exists:accounts,id,ledger_id,' . $ledgerId,
            'to_account_id' => 'required_if:type,transfer|nullable|exists:accounts,id,ledger_id,' . $ledgerId,
            'category_id'   => 'required_unless:type,transfer|nullable|exists:categories,id,ledger_id,' . $ledgerId,
            'date'          => 'required',
            'notes'         => 'nullable|string',
        ]);

        return DB::transaction(function () use ($transaction, $validated) {
            $oldAmount = $transaction->amount;
            $installment = $transaction->installment_id ? $transaction->installment()->lockForUpdate()->first() : null;

            $transaction->update($validated);

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

            $transaction->delete();

            return response()->json(['message' => 'Transaction deleted successfully']);
        });
    }

    public function activeMonths(Request $request, Ledger $ledger)
    {
        $this->authorizeLedger($ledger);

        // This finds every unique Month/Year combo in your transactions
        return $ledger->transactions()
            ->selectRaw('MONTH(date) as month, YEAR(date) as year')
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