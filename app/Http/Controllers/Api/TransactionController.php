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
        if (!$ledger->users->contains($request->user())) {
            return response()->json(['error' => 'Unauthorized access.'], 403);
        }

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
        if (!$ledger->users->contains($request->user())) {
            return response()->json(['error' => 'Unauthorized access.'], 403);
        }

        $validated = $request->validate([
            'title'         => 'required|string|max:255',
            'amount'        => 'required|numeric|min:0',
            'type'          => 'required|in:income,expense,transfer',
            'date'          => 'required',
            'account_id'    => 'required|exists:accounts,id',
            'to_account_id' => 'required_if:type,transfer|nullable|exists:accounts,id',
            'category_id'   => 'required_unless:type,transfer|nullable|exists:categories,id',
            'notes'         => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request, $ledger, $validated) {
            // 1. Create the transaction
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

            // 2. CASHEW MAGIC: Update Balances
            $sourceAccount = Account::find($validated['account_id']);

            if ($validated['type'] === 'transfer') {
                $destAccount = Account::find($validated['to_account_id']);
                $sourceAccount->decrement('balance', $validated['amount']);
                $destAccount->increment('balance', $validated['amount']);
            } elseif ($validated['type'] === 'expense') {
                $sourceAccount->decrement('balance', $validated['amount']);
            } else {
                $sourceAccount->increment('balance', $validated['amount']);
            }

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
        $validated = $request->validate([
            'title'         => 'required|string',
            'amount'        => 'required|numeric',
            'type'          => 'required|in:income,expense,transfer',
            'account_id'    => 'required|exists:accounts,id',
            'to_account_id' => 'required_if:type,transfer|nullable|exists:accounts,id',
            'category_id'   => 'required_unless:type,transfer|nullable|exists:categories,id',
            'date'          => 'required',
        ]);

        return DB::transaction(function () use ($transaction, $validated) {
            // 1. REVERT OLD BALANCES
            $oldSource = Account::find($transaction->account_id);
            if ($transaction->type === 'transfer') {
                $oldDest = Account::find($transaction->to_account_id);
                $oldSource->increment('balance', $transaction->amount);
                $oldDest->decrement('balance', $transaction->amount);
            } elseif ($transaction->type === 'expense') {
                $oldSource->increment('balance', $transaction->amount);
            } else {
                $oldSource->decrement('balance', $transaction->amount);
            }

            // 2. UPDATE TRANSACTION DATA
            $transaction->update($validated);

            // 3. APPLY NEW BALANCES
            $newSource = Account::find($validated['account_id']);
            if ($validated['type'] === 'transfer') {
                $newDest = Account::find($validated['to_account_id']);
                $newSource->decrement('balance', $validated['amount']);
                $newDest->increment('balance', $validated['amount']);
            } elseif ($validated['type'] === 'expense') {
                $newSource->decrement('balance', $validated['amount']);
            } else {
                $newSource->increment('balance', $validated['amount']);
            }

            return response()->json($transaction->load('category', 'account', 'toAccount'));
        });
    }

    /**
     * Delete a transaction and revert balances.
     */
   public function destroy($id)
{
    $transaction = \App\Models\Transaction::findOrFail($id);

    // --- 1. REFUND THE BANK ACCOUNT BALANCE ---
    $account = \App\Models\Account::find($transaction->account_id);
    if ($account) {
        // If it was an expense (like a Cicilan payment), give the money back (+)
        if ($transaction->type === 'expense') {
            $account->balance += $transaction->amount;
        } 
        // If it was income, take the money back (-)
        elseif ($transaction->type === 'income') {
            $account->balance -= $transaction->amount;
        } 
        // If it was a transfer, rewind both accounts
        elseif ($transaction->type === 'transfer') {
            $account->balance += $transaction->amount; // Give back to sender
            
            $toAccount = \App\Models\Account::find($transaction->to_account_id);
            if ($toAccount) {
                $toAccount->balance -= $transaction->amount; // Take from receiver
                $toAccount->save();
            }
        }
        $account->save();
    }

    // --- 2. THE SMARTER TITLE DETECTIVE (Rewind Cicilan) ---
    if (str_contains($transaction->title, 'Cicilan')) {
        
        // Clean the title
        $baseTitle = str_replace(' (Cicilan Payment)', '', $transaction->title);
        $baseTitle = str_replace('Cicilan: ', '', $baseTitle); 
        $baseTitle = trim($baseTitle); 

        // Find the matching Installment
        $installment = \App\Models\Installment::where('title', $baseTitle)
                            ->where('account_id', $transaction->account_id)
                            ->first();

        // If found, rewind the progress bar!
        if ($installment) {
            $installment->paid_amount -= $transaction->amount;
            
            if ($installment->paid_amount < 0) {
                $installment->paid_amount = 0;
            }
            
            $installment->save();
        }
    }

    // --- 3. FINALLY, DELETE THE RECORD ---
    $transaction->delete();

    return response()->json(['message' => 'Transaction deleted successfully']);
}
public function activeMonths($ledgerId)
{
    // This finds every unique Month/Year combo in your transactions
    return \App\Models\Transaction::where('ledger_id', $ledgerId)
        ->selectRaw('MONTH(date) as month, YEAR(date) as year')
        ->distinct()
        ->orderBy('year', 'desc')
        ->orderBy('month', 'desc')
        ->get();
}
public function netWorthTrend($ledgerId)
{
    // 1. Get current total net worth right now
    $currentNetWorth = \App\Models\Account::where('ledger_id', $ledgerId)->sum('balance');

    // 2. Get all income and expenses from the last 30 days
    $startDate = now()->subDays(30)->startOfDay();
    $transactions = \App\Models\Transaction::where('ledger_id', $ledgerId)
        ->where('date', '>=', $startDate)
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