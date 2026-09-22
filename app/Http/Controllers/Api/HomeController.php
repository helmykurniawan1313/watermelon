<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ledger;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index(Request $request, Ledger $ledger)
    {
        $month = now()->month;
        $year = now()->year;

        // 1. Net Worth (Total of all accounts). `balance` is a computed accessor
        // (base_balance + transaction effects), not the stale `balance` DB column,
        // so accounts must be loaded as models rather than summed via SQL.
        $accounts = \App\Models\Account::withComputedBalances($ledger->accounts);
        $netWorth = $accounts->sum('balance');
        $totalTxCount = $ledger->transactions()->count();

        // 2. Monthly Stats Grid
        $monthlyTransactions = $ledger->transactions()
            ->whereMonth('date', $month)
            ->whereYear('date', $year)
            ->get();

        $expense = $monthlyTransactions->where('type', 'expense')->sum('amount');
        $income = $monthlyTransactions->where('type', 'income')->sum('amount');

        // 3. Installments / Upcoming (Example logic)
        $upcoming = $ledger->installments()->sum('monthly_amount');

        return response()->json([
            'net_worth' => (float)$netWorth,
            'total_tx_count' => $totalTxCount,
            'accounts' => $accounts,
            'stats' => [
                ['label' => 'Expense', 'value' => $expense, 'color' => 'text-red-400', 'count' => $monthlyTransactions->where('type', 'expense')->count()],
                ['label' => 'Income', 'value' => $income, 'color' => 'text-green-400', 'count' => $monthlyTransactions->where('type', 'income')->count()],
                ['label' => 'Upcoming', 'value' => $upcoming, 'color' => 'text-blue-400', 'count' => $ledger->installments()->count()],
                ['label' => 'Overdue', 'value' => 0, 'color' => 'text-purple-400', 'count' => 0],
            ],
            // Budget Example: Hardcoded limit for now
            'budget' => [
                'name' => 'Belanja Bulanan',
                'spent' => $expense,
                'limit' => 10000000, 
            ]
        ]);
    }
}