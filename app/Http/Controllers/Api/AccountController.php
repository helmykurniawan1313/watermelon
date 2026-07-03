<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Ledger;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function index(Ledger $ledger)
    {
        return response()->json($ledger->accounts);
    }

    public function store(Request $request, Ledger $ledger)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'balance' => 'required|numeric',
            'icon' => 'nullable|string',
            'color' => 'nullable|string',
        ]);

        $account = $ledger->accounts()->create($validated);
        return response()->json($account, 201);
    }

    /**
     * UPDATE an existing account
     */
    public function update(Request $request, Account $account)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'balance' => 'required|numeric',
            'icon' => 'nullable|string',
            'color' => 'nullable|string',
        ]);

        $account->update($validated);
        
        return response()->json($account);
    }

    /**
     * DELETE an account
     */
    public function destroy(Account $account)
    {
        // Note: You might want to prevent deleting if there are transactions, 
        // or let Cascade Delete handle it.
        $account->delete();
        
        return response()->json(['message' => 'Account deleted successfully']);
    }

    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'accounts' => 'required|array',
            'accounts.*.id' => 'required|exists:accounts,id',
            'accounts.*.sort_order' => 'required|integer'
        ]);

        foreach ($validated['accounts'] as $account) {
            \App\Models\Account::where('id', $account['id'])->update(['sort_order' => $account['sort_order']]);
        }

        return response()->json(['message' => 'Order updated successfully']);
    }
}