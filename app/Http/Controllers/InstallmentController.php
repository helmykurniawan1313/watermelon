<?php

namespace App\Http\Controllers\Api; // Make sure you are in the Api folder or adjust namespace

use App\Http\Controllers\Controller;
use App\Models\Ledger;
use App\Models\Installment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InstallmentController extends Controller
{
    public function index(Request $request, Ledger $ledger)
    {
        return response()->json($ledger->installments()->with(['account', 'category'])->get());
    }

    public function store(Request $request, Ledger $ledger)
    {
        $validated = $request->validate([
            'title' => 'required|string',
            'total_amount' => 'required|numeric',
            'duration_months' => 'required|integer|min:1',
            'account_id' => 'required|exists:accounts,id',
            'category_id' => 'required|exists:categories,id',
        ]);

        $validated['monthly_amount'] = $validated['total_amount'] / $validated['duration_months'];
        $validated['paid_amount'] = 0;

        $installment = $ledger->installments()->create($validated);
        return response()->json($installment, 201);
    }

    // THE CASHEW WORKFLOW: Pay a month
public function pay($id)
{
    $installment = \App\Models\Installment::findOrFail($id);
    
    // Just increase the progress bar, nothing else!
    $installment->paid_amount += $installment->monthly_amount;
    $installment->save();

    return response()->json(['message' => 'Progress updated']);
}
}