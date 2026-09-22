<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ledger;
use App\Models\User;
use Illuminate\Http\Request;

class LedgerController extends Controller
{
    /**
     * List all ledgers the current user belongs to.
     */
    public function index(Request $request)
    {
        return response()->json($request->user()->ledgers);
    }

    /**
     * Create a new ledger and attach the creator as its owner.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'currency' => 'nullable|string|max:10',
        ]);

        $ledger = Ledger::create([
            'name' => $validated['name'],
            'currency' => $validated['currency'] ?? 'IDR',
        ]);

        $ledger->users()->attach($request->user()->id, ['role' => 'owner']);

        return response()->json($ledger, 201);
    }

    /**
     * Share a ledger with another user by email.
     */
    public function addUser(Request $request, Ledger $ledger)
    {
        $this->authorizeLedger($ledger);

        $validated = $request->validate([
            'email' => 'required|email|exists:users,email',
            'role' => 'nullable|string|in:owner,member',
        ]);

        $user = User::where('email', $validated['email'])->firstOrFail();

        if ($ledger->users->contains($user->id)) {
            return response()->json(['error' => 'User already has access to this ledger.'], 422);
        }

        $ledger->users()->attach($user->id, ['role' => $validated['role'] ?? 'member']);

        return response()->json(['message' => 'User added to ledger successfully.']);
    }
}
