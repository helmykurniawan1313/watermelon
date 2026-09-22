<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Ledger;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function index(Request $request, Ledger $ledger)
    {
        $this->authorizeLedger($ledger);

        return response()->json(Account::withComputedBalances($ledger->accounts()->orderBy('sort_order')->get()));
    }

    public function store(Request $request, Ledger $ledger)
    {
        $this->authorizeLedger($ledger);

        $validated = $request->validate([
            'name' => 'required|string',
            'balance' => 'required|numeric',
            'icon' => 'nullable|string',
            'color' => 'nullable|string',
        ]);

        // The submitted "balance" is the account's starting point (no transactions yet),
        // so it's stored as base_balance; the effective `balance` is computed live.
        $account = $ledger->accounts()->create([
            'name' => $validated['name'],
            'base_balance' => $validated['balance'],
            'icon' => $validated['icon'] ?? null,
            'color' => $validated['color'] ?? null,
        ]);
        return response()->json($account, 201);
    }

    /**
     * UPDATE an existing account
     */
    public function update(Request $request, Account $account)
    {
        $this->authorizeLedger($account->ledger);

        $validated = $request->validate([
            'name' => 'required|string',
            'balance' => 'required|numeric',
            'icon' => 'nullable|string',
            'color' => 'nullable|string',
        ]);

        // The submitted "balance" is what the user wants the *current effective* balance
        // to become. Since balance = base_balance + net(past transactions), solve for the
        // base_balance that makes that true, rather than overwriting it directly (which
        // would double-count/ignore already-applied transactions).
        $currentEffectiveBalance = $account->balance;
        $adjustment = $validated['balance'] - $currentEffectiveBalance;

        $account->update([
            'name' => $validated['name'],
            'base_balance' => $account->base_balance + $adjustment,
            'icon' => $validated['icon'] ?? $account->icon,
            'color' => $validated['color'] ?? $account->color,
        ]);

        return response()->json($account);
    }

    /**
     * DELETE an account
     */
    public function destroy(Request $request, Account $account)
    {
        $this->authorizeLedger($account->ledger);

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

        $ids = collect($validated['accounts'])->pluck('id');
        $accounts = Account::whereIn('id', $ids)->with('ledger.users')->get()->keyBy('id');

        foreach ($accounts as $account) {
            $this->authorizeLedger($account->ledger);
        }

        foreach ($validated['accounts'] as $entry) {
            $accounts[$entry['id']]->update(['sort_order' => $entry['sort_order']]);
        }

        return response()->json(['message' => 'Order updated successfully']);
    }
}