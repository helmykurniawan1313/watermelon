<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Installment;
use App\Models\Ledger;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    /**
     * Logo choice: "auto", "badge", "logo:<brand>", a small uploaded PNG/JPEG/WebP data URL,
     * or a legacy emoji. Anything else (e.g. SVG, which can carry scripts) is rejected.
     */
    private const ICON_RULE = [
        'nullable', 'string', 'max:60000',
        'regex:/^(auto|badge|logo:[a-z0-9_-]{1,40}|data:image\/(png|jpeg|webp);base64,[A-Za-z0-9+\/=]+|[^<>]{1,16})$/',
    ];

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
            'icon' => self::ICON_RULE,
            'color' => 'nullable|string',
            'user_id' => $this->ownerRule($ledger),
        ]);

        // The submitted "balance" is the account's starting point (no transactions yet),
        // so it's stored as base_balance; the effective `balance` is computed live.
        $account = $ledger->accounts()->create([
            'name' => $validated['name'],
            'base_balance' => $validated['balance'],
            'icon' => $validated['icon'] ?? 'auto',
            'color' => $validated['color'] ?? '#1F9D61', // column is NOT NULL
            // Default owner: whoever creates it. null = shared/joint account.
            'user_id' => array_key_exists('user_id', $validated) ? $validated['user_id'] : $request->user()->id,
        ]);
        ActivityLog::record($ledger->id, 'created', 'account', $account->id, "added account {$account->name}");
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
            'icon' => self::ICON_RULE,
            'color' => 'nullable|string',
            'user_id' => $this->ownerRule($account->ledger),
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
            'user_id' => array_key_exists('user_id', $validated) ? $validated['user_id'] : $account->user_id,
        ]);
        ActivityLog::record($account->ledger_id, 'updated', 'account', $account->id,
            abs($adjustment) >= 0.01 ? "adjusted {$account->name} balance to " . ActivityLog::money($validated['balance']) : "edited account {$account->name}");

        return response()->json($account);
    }

    /**
     * DELETE an account
     */
    public function destroy(Request $request, Account $account)
    {
        $this->authorizeLedger($account->ledger);

        // Cicilan plans reference their account without cascading, so the delete would
        // fail with a foreign-key error. Ask the user to move or remove the plan first.
        $plans = Installment::where('account_id', $account->id)->pluck('title');
        if ($plans->isNotEmpty()) {
            return response()->json([
                'message' => 'This account is used by cicilan: ' . $plans->implode(', ') . '. Change or delete the cicilan first.',
            ], 422);
        }

        // The account's own transactions and transfers to/from it are removed by the
        // database cascade (the app warns about this before calling the endpoint).
        ActivityLog::record($account->ledger_id, 'deleted', 'account', $account->id, "deleted account {$account->name}");
        $account->delete();

        return response()->json(['message' => 'Account deleted successfully']);
    }

    /**
     * An account's owner must be a member of the household (or null for a shared account).
     */
    private function ownerRule(Ledger $ledger): array
    {
        return ['sometimes', 'nullable', 'integer', \Illuminate\Validation\Rule::in($ledger->users->pluck('id')->all())];
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