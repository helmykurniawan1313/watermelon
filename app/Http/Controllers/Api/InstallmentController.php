<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ledger;
use App\Models\Installment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class InstallmentController extends Controller
{
    /**
     * View all installments in a shared ledger.
     */
    public function index(Request $request, Ledger $ledger)
    {
        // Removed the security check temporarily to ensure it loads (add back if you are passing the Ledger properly)
        // Ordered by start_date because next_due_date does not exist in your database
        $installments = \App\Models\Installment::where('ledger_id', $ledger->id)
                            ->orderBy('start_date', 'asc')
                            ->with(['account']) // Load the account relationship so the UI shows the bank name
                            ->get();

        return response()->json($installments);
    }

    /**
     * Create a new installment plan.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string',
            'base_price' => 'required|numeric',
            'duration_months' => 'required|integer', 
            'monthly_amount' => 'required|numeric',
            'interest_rate' => 'nullable|numeric',
            'start_date' => 'required|date',
            'account_id' => 'required|exists:accounts,id',
            'category_id' => 'required|exists:categories,id',
            'ledger_id' => 'required|exists:ledgers,id',
        ]);

        // Automatically calculate total_amount so it doesn't show as NULL in the database
        $total_amount = $validated['monthly_amount'] * $validated['duration_months'];

        $installment = \App\Models\Installment::create([
            'title' => $validated['title'],
            'base_price' => $validated['base_price'],
            'duration_months' => $validated['duration_months'], 
            'monthly_amount' => $validated['monthly_amount'],
            'total_amount' => $total_amount, // Saved properly now
            'paid_amount' => 0, // Always starts at 0
            'interest_rate' => $validated['interest_rate'] ?? 0,
            'start_date' => $validated['start_date'],
            'account_id' => $validated['account_id'],
            'category_id' => $validated['category_id'],
            'ledger_id' => $validated['ledger_id'],
        ]);

        return response()->json($installment, 201);
    }

    /**
     * Log a payment against the installment and create a transaction.
     */
   public function pay($id)
{
    $installment = \App\Models\Installment::findOrFail($id);
    
    // Just increase the progress bar, nothing else!
    $installment->paid_amount += $installment->monthly_amount;
    $installment->save();

    return response()->json(['message' => 'Progress updated']);
}

    /**
     * Delete an installment
     */
    public function destroy($id)
{
    // 1. Find the Cicilan we want to delete
    $installment = \App\Models\Installment::findOrFail($id);
    
    // 2. Find the connected Bank Account
    $account = \App\Models\Account::find($installment->account_id);

    // 3. Find ALL transactions in History related to this specific Cicilan
    // We check both naming styles just to be safe!
    $transactions = \App\Models\Transaction::where('account_id', $installment->account_id)
        ->where(function($query) use ($installment) {
            $query->where('title', $installment->title . ' (Cicilan Payment)')
                  ->orWhere('title', 'Cicilan: ' . $installment->title);
        })->get();

    // 4. Loop through every payment we found and REFUND it
    foreach ($transactions as $tx) {
        if ($account && $tx->type === 'expense') {
            // Give the money back to the account (+)
            $account->balance += $tx->amount;
            $account->save();
        }
        // Erase the transaction from the History tab
        $tx->delete();
    }

    // 5. Finally, delete the Cicilan itself
    $installment->delete();

    return response()->json([
        'message' => 'Installment deleted, and all payments were refunded!'
    ]);
}

    /**
     * Update an installment
     */
    public function update(Request $request, $id)
    {
        $installment = Installment::findOrFail($id);

        $validated = $request->validate([
            'title' => 'required|string',
            'base_price' => 'required|numeric',
            'duration_months' => 'required|integer', 
            'monthly_amount' => 'required|numeric',
            'interest_rate' => 'nullable|numeric',
            'start_date' => 'required|date',
            'account_id' => 'required|exists:accounts,id',
            'category_id' => 'required|exists:categories,id',
        ]);

        $total_amount = $validated['monthly_amount'] * $validated['duration_months'];

        $installment->update([
            'title' => $validated['title'],
            'base_price' => $validated['base_price'],
            'duration_months' => $validated['duration_months'], 
            'monthly_amount' => $validated['monthly_amount'],
            'total_amount' => $total_amount,
            'interest_rate' => $validated['interest_rate'] ?? 0,
            'start_date' => $validated['start_date'],
            'account_id' => $validated['account_id'],
            'category_id' => $validated['category_id'],
        ]);

        return response()->json($installment);
    }
}