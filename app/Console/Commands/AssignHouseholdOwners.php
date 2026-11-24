<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off setup for an existing shared household after the couples update:
 * sets roles, gives accounts an owner (by name keyword), and attributes transactions.
 *
 *   php artisan households:assign-owners 1 --owner=helmy@example.com --partner=rika@example.com:rika
 *   ...same with --apply to write the changes
 */
class AssignHouseholdOwners extends Command
{
    protected $signature = 'households:assign-owners
        {ledger : Household (ledger) id}
        {--owner= : Email of the household owner; gets every account not matched by --partner}
        {--partner=* : email:keyword — accounts whose name contains the keyword belong to this member (repeatable)}
        {--transactions-to= : Email to attribute ALL existing transactions to (defaults to --owner); use "keep" to leave them}
        {--only-unowned : Only touch accounts that have no owner yet}
        {--apply : Write the changes (without it, nothing is changed)}';

    protected $description = 'Set household roles, account owners and transaction authors for an existing shared ledger';

    public function handle(): int
    {
        $ledger = Ledger::with('users')->find($this->argument('ledger'));
        if (!$ledger) {
            $this->error('No ledger with that id.');
            return self::FAILURE;
        }

        $owner = $this->member($ledger, $this->option('owner'));
        if (!$owner) return self::FAILURE;

        $partners = [];
        foreach ($this->option('partner') as $spec) {
            [$email, $keyword] = array_pad(explode(':', $spec, 2), 2, null);
            if (!$keyword) {
                $this->error("--partner must look like email:keyword (got \"{$spec}\").");
                return self::FAILURE;
            }
            $user = $this->member($ledger, $email);
            if (!$user) return self::FAILURE;
            $partners[] = [$user, mb_strtolower($keyword)];
        }

        $txTarget = $this->option('transactions-to') ?? $owner->email;
        $txUser = $txTarget === 'keep' ? null : $this->member($ledger, $txTarget);
        if ($txTarget !== 'keep' && !$txUser) return self::FAILURE;

        // ---- plan
        $accounts = $ledger->accounts()->orderBy('sort_order')->get();
        if ($this->option('only-unowned')) $accounts = $accounts->whereNull('user_id');
        $plan = $accounts->map(function (Account $a) use ($partners, $owner) {
            $to = $owner;
            foreach ($partners as [$user, $keyword]) {
                if (str_contains(mb_strtolower($a->name), $keyword)) { $to = $user; break; }
            }
            return [$a, $to];
        });

        $this->info("Household #{$ledger->id} \"{$ledger->name}\"");
        $this->line("  Owner role → {$owner->name} <{$owner->email}>; everyone else → member");
        $this->table(['Account', 'Owner now', 'Owner after'], $plan->map(fn ($p) => [
            $p[0]->name, optional($ledger->users->firstWhere('id', $p[0]->user_id))->name ?? '— (shared)', $p[1]->name,
        ])->all());
        $txCount = Transaction::where('ledger_id', $ledger->id)->count();
        $this->line($txUser ? "  All {$txCount} transactions → logged by {$txUser->name}" : '  Transactions: unchanged');

        if (!$this->option('apply')) {
            $this->warn('Dry run: nothing was changed. Add --apply to write these changes.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($ledger, $owner, $plan, $txUser) {
            foreach ($ledger->users as $u) {
                $ledger->users()->updateExistingPivot($u->id, ['role' => $u->id === $owner->id ? 'owner' : 'member']);
            }
            foreach ($plan as [$account, $to]) {
                $account->update(['user_id' => $to->id]);
            }
            if ($txUser) {
                Transaction::where('ledger_id', $ledger->id)->update(['user_id' => $txUser->id]);
            }
        });

        $this->info('Done.');
        return self::SUCCESS;
    }

    private function member(Ledger $ledger, ?string $email): ?User
    {
        if (!$email) {
            $this->error('Missing email (use --owner=you@example.com).');
            return null;
        }
        $user = $ledger->users->firstWhere('email', $email);
        if (!$user) {
            $this->error("{$email} is not a member of household #{$ledger->id}.");
        }
        return $user;
    }
}
