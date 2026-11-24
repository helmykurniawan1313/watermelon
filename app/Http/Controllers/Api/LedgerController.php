<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Ledger;
use App\Models\LedgerInvitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A ledger is a household: one person, or a couple sharing accounts and transactions.
 */
class LedgerController extends Controller
{
    private const MAX_MEMBERS = 6;

    /**
     * Households the current user belongs to, with their members.
     */
    public function index(Request $request)
    {
        return response()->json(
            $request->user()->ledgers()->with('users:id,name,email')->get()->map(fn ($l) => $this->present($l))
        );
    }

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

        return response()->json($this->present($ledger->load('users:id,name,email')), 201);
    }

    public function update(Request $request, Ledger $ledger)
    {
        $this->authorizeLedger($ledger);
        $validated = $request->validate(['name' => 'required|string|max:255']);
        $ledger->update($validated);

        return response()->json($this->present($ledger->load('users:id,name,email')));
    }

    /**
     * Create a single-use invite code (valid 7 days) the member shares with their partner.
     */
    public function invite(Request $request, Ledger $ledger)
    {
        $this->authorizeLedger($ledger);

        if ($ledger->users()->count() >= self::MAX_MEMBERS) {
            return response()->json(['message' => 'This household is full.'], 422);
        }

        // Only one open code per household at a time: replace any unused one.
        LedgerInvitation::where('ledger_id', $ledger->id)->whereNull('accepted_at')->delete();

        $invitation = LedgerInvitation::create([
            'ledger_id' => $ledger->id,
            'inviter_id' => $request->user()->id,
            'code' => LedgerInvitation::newCode(),
            'expires_at' => now()->addDays(7),
        ]);

        return response()->json(['code' => $invitation->code, 'expires_at' => $invitation->expires_at], 201);
    }

    /**
     * Look up a code before joining, so the app can show "Join Helmy's household?".
     */
    public function previewInvite(Request $request, string $code)
    {
        $invitation = $this->findUsable($code);

        return response()->json([
            'household' => $invitation->ledger->name,
            'inviter' => $invitation->inviter->name,
            'already_member' => $invitation->ledger->users->contains($request->user()->id),
        ]);
    }

    public function acceptInvite(Request $request, string $code)
    {
        $user = $request->user();

        return DB::transaction(function () use ($code, $user) {
            $invitation = $this->findUsable($code, lock: true);
            $ledger = $invitation->ledger;

            if (!$ledger->users->contains($user->id)) {
                if ($ledger->users->count() >= self::MAX_MEMBERS) {
                    abort(response()->json(['message' => 'This household is full.'], 422));
                }
                $ledger->users()->attach($user->id, ['role' => 'member']);
                ActivityLog::record($ledger->id, 'joined', 'member', $user->id, "{$user->name} joined the household");
            }

            $invitation->update(['accepted_by' => $user->id, 'accepted_at' => now()]);

            return response()->json($this->present($ledger->fresh()->load('users:id,name,email')));
        });
    }

    /**
     * Leave a household, or (as its owner) remove someone from it.
     */
    public function removeMember(Request $request, Ledger $ledger, User $user)
    {
        $this->authorizeLedger($ledger);
        $me = $request->user();
        $isSelf = $me->id === $user->id;
        $myRole = $ledger->users->firstWhere('id', $me->id)?->pivot->role;

        if (!$isSelf && $myRole !== 'owner') {
            return response()->json(['message' => 'Only the household owner can remove someone.'], 403);
        }
        if (!$ledger->users->contains($user->id)) {
            return response()->json(['message' => 'That person is not in this household.'], 404);
        }
        if ($ledger->users->count() === 1) {
            return response()->json(['message' => "You're the only member, so you can't leave this household."], 422);
        }

        DB::transaction(function () use ($ledger, $user, $isSelf, $me) {
            // Their accounts stay in the household as shared accounts.
            $ledger->accounts()->where('user_id', $user->id)->update(['user_id' => null]);
            $ledger->users()->detach($user->id);

            // Keep an owner around if the owner left.
            if (!$ledger->users()->wherePivot('role', 'owner')->exists()) {
                $next = $ledger->users()->orderBy('ledger_user.created_at')->first();
                $next && $ledger->users()->updateExistingPivot($next->id, ['role' => 'owner']);
            }

            ActivityLog::record($ledger->id, 'left', 'member', $user->id,
                $isSelf ? "{$user->name} left the household" : "{$me->name} removed {$user->name}");
        });

        return response()->json(['message' => 'Done.']);
    }

    /**
     * Latest activity in the household (newest first).
     */
    public function activity(Request $request, Ledger $ledger)
    {
        $this->authorizeLedger($ledger);
        $limit = min(max((int) $request->query('limit', 50), 1), 200);

        return response()->json(
            ActivityLog::where('ledger_id', $ledger->id)
                ->with('user:id,name')
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
        );
    }

    private function findUsable(string $code, bool $lock = false): LedgerInvitation
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
        $query = LedgerInvitation::where('code', $code)->with(['ledger.users', 'inviter']);
        $invitation = ($lock ? $query->lockForUpdate() : $query)->first();

        if (!$invitation || !$invitation->isUsable()) {
            abort(response()->json(['message' => 'This invite code is invalid or has expired.'], 404));
        }

        return $invitation;
    }

    private function present(Ledger $ledger): array
    {
        return [
            'id' => $ledger->id,
            'name' => $ledger->name,
            'currency' => $ledger->currency,
            'members' => $ledger->users->map(fn ($u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->pivot->role,
            ])->values(),
        ];
    }
}
