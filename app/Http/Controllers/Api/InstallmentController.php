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
        $this->authorizeLedger($ledger);

        // Ordered by start_date (no next_due_date field is tracked)
        $installments = \App\Models\Installment::where('ledger_id', $ledger->id)
                            ->orderBy('start_date', 'asc')
                            ->with(['account']) // Load the account relationship so the UI shows the bank name
                            ->withCount('transactions')
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

        $this->authorizeLedger(Ledger::findOrFail($validated['ledger_id']));

        $request->validate([
            'account_id' => 'exists:accounts,id,ledger_id,' . $validated['ledger_id'],
            'category_id' => 'exists:categories,id,ledger_id,' . $validated['ledger_id'],
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
     * Log a payment against the installment: creates a real expense transaction,
     * debits the account, and advances the progress bar.
     */
    public function pay(Request $request, $id)
    {
        $installment = \App\Models\Installment::findOrFail($id);
        $this->authorizeLedger($installment->ledger);

        return DB::transaction(function () use ($installment) {
            $installment = \App\Models\Installment::where('id', $installment->id)->lockForUpdate()->firstOrFail();

            if ($installment->paid_amount >= $installment->total_amount) {
                abort(response()->json(['error' => 'Installment is already fully paid.'], 422));
            }

            $amount = min($installment->monthly_amount, $installment->total_amount - $installment->paid_amount);

            $cicilanCategoryId = \App\Models\Category::where('ledger_id', $installment->ledger_id)
                ->where('name', 'Cicilan')
                ->value('id') ?? $installment->category_id;

            $transaction = \App\Models\Transaction::create([
                'ledger_id'      => $installment->ledger_id,
                'account_id'     => $installment->account_id,
                'user_id'        => request()->user()->id,
                'category_id'    => $cicilanCategoryId,
                'installment_id' => $installment->id,
                'amount'         => $amount,
                'date'           => now(),
                'title'          => $installment->title . ' (Installment Payment)',
                'type'           => 'expense',
            ]);

            $installment->paid_amount += $amount;
            $installment->save();

            return response()->json([
                'message' => 'Payment logged successfully',
                'installment' => $installment,
                'transaction' => $transaction,
            ]);
        });
    }

    /**
     * Delete an installment, refunding any payments made through it.
     */
    public function destroy($id)
    {
        $installment = \App\Models\Installment::findOrFail($id);
        $this->authorizeLedger($installment->ledger);

        DB::transaction(function () use ($installment) {
            \App\Models\Transaction::where('installment_id', $installment->id)->delete();

            $installment->delete();
        });

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
        $this->authorizeLedger($installment->ledger);

        $validated = $request->validate([
            'title' => 'required|string',
            'base_price' => 'required|numeric',
            'duration_months' => 'required|integer', 
            'monthly_amount' => 'required|numeric',
            'interest_rate' => 'nullable|numeric',
            'start_date' => 'required|date',
            'account_id' => 'required|exists:accounts,id,ledger_id,' . $installment->ledger_id,
            'category_id' => 'required|exists:categories,id,ledger_id,' . $installment->ledger_id,
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