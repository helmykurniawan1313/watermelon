<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ledger;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ReportController extends Controller
{
    /**
     * Get the income, expense, and balance summary for a specific month.
     */
   public function summary(Request $request, Ledger $ledger)
{
    $this->authorizeLedger($ledger);

    // 1. Get month/year from query, or default to current
    $month = $request->query('month', now()->month);
    $year = $request->query('year', now()->year);

    // 2. Net Worth: Sum of all account balances in this ledger.
    // Must load models (not a raw SQL sum), since `balance` is a computed accessor
    // that excludes future-dated transactions.
    $netWorth = \App\Models\Account::withComputedBalances($ledger->accounts()->get())->sum('balance');

    // 3. Transactions for the selected period
    $transactions = $ledger->transactions()
        ->with(['category', 'account'])
        ->whereMonth('date', $month)
        ->whereYear('date', $year)
        ->orderBy('date', 'desc')
        ->get();

    // 4. Monthly Math
    $income = $transactions->where('type', 'income')->sum('amount');
    $expense = $transactions->where('type', 'expense')->sum('amount');

    return response()->json([
        'ledger_name' => $ledger->name,
        'net_worth' => (float)$netWorth,
        'period_summary' => [
            'income' => (float)$income,
            'expense' => (float)$expense,
            'balance' => (float)($income - $expense),
        ],
        'transactions' => $transactions
    ]);
}
}