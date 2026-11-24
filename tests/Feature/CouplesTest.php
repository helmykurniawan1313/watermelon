<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Ledger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CouplesTest extends TestCase
{
    use RefreshDatabase;

    private function signUp(string $name, string $email): array
    {
        $res = $this->postJson('/api/register', [
            'name' => $name, 'email' => $email, 'password' => 'password', 'password_confirmation' => 'password',
        ])->assertCreated();
        $user = User::where('email', $email)->first();
        return [$user, $user->ledgers()->first()];
    }

    public function test_signing_up_creates_a_private_household(): void
    {
        [$user, $ledger] = $this->signUp('Helmy', 'helmy@test.dev');

        $this->assertNotNull($ledger);
        $this->assertSame('owner', $ledger->users()->first()->pivot->role);
    }

    public function test_partner_joins_with_an_invite_code_and_sees_shared_data(): void
    {
        [$helmy, $household] = $this->signUp('Helmy', 'helmy@test.dev');
        [$rika] = $this->signUp('Rika', 'rika@test.dev');

        Sanctum::actingAs($helmy);
        $code = $this->postJson("/api/ledgers/{$household->id}/invite")->assertCreated()->json('code');
        $account = $this->postJson("/api/ledgers/{$household->id}/accounts", ['name' => 'BCA', 'balance' => 100])->assertCreated()->json();
        $this->assertSame($helmy->id, $account['user_id']); // owner defaults to the creator

        Sanctum::actingAs($rika);
        $this->getJson("/api/ledgers/{$household->id}/accounts")->assertForbidden(); // not a member yet
        $this->getJson("/api/invites/{$code}")->assertOk()->assertJsonPath('inviter', 'Helmy');
        $this->postJson("/api/invites/{$code}/accept")->assertOk()->assertJsonCount(2, 'members');
        $this->getJson("/api/ledgers/{$household->id}/accounts")->assertOk()->assertJsonCount(1);

        // single use
        $this->postJson("/api/invites/{$code}/accept")->assertNotFound();
        $this->assertTrue(ActivityLog::where('ledger_id', $household->id)->where('action', 'joined')->exists());
    }

    public function test_expired_or_unknown_codes_are_rejected(): void
    {
        [$helmy, $household] = $this->signUp('Helmy', 'helmy@test.dev');
        Sanctum::actingAs($helmy);
        $code = $this->postJson("/api/ledgers/{$household->id}/invite")->json('code');
        \App\Models\LedgerInvitation::where('code', $code)->update(['expires_at' => now()->subMinute()]);

        $this->getJson("/api/invites/{$code}")->assertNotFound();
        $this->getJson('/api/invites/NOPE1234')->assertNotFound();
    }

    public function test_activity_log_records_who_did_what(): void
    {
        [$helmy, $household] = $this->signUp('Helmy', 'helmy@test.dev');
        Sanctum::actingAs($helmy);
        $acc = $this->postJson("/api/ledgers/{$household->id}/accounts", ['name' => 'Cash', 'balance' => 0])->json('id');
        $cat = $this->postJson("/api/ledgers/{$household->id}/categories", ['name' => 'Food', 'icon' => 'i:food', 'color' => '#F0782D'])->json('id');
        $this->postJson("/api/ledgers/{$household->id}/transactions", [
            'title' => 'Bakso', 'amount' => 15000, 'type' => 'expense', 'date' => now()->subMinute()->format('Y-m-d H:i:s'),
            'account_id' => $acc, 'category_id' => $cat,
        ])->assertCreated();

        $feed = $this->getJson("/api/ledgers/{$household->id}/activity")->assertOk()->json();
        $this->assertSame('added expense "Bakso" · Rp 15.000', $feed[0]['summary']);
        $this->assertSame('Helmy', $feed[0]['user']['name']);
    }

    public function test_account_owner_must_be_a_household_member(): void
    {
        [$helmy, $household] = $this->signUp('Helmy', 'helmy@test.dev');
        [$stranger] = $this->signUp('Stranger', 'x@test.dev');
        Sanctum::actingAs($helmy);

        $this->postJson("/api/ledgers/{$household->id}/accounts", ['name' => 'Joint', 'balance' => 0, 'user_id' => $stranger->id])->assertStatus(422);
        $this->postJson("/api/ledgers/{$household->id}/accounts", ['name' => 'Joint', 'balance' => 0, 'user_id' => null])
            ->assertCreated()->assertJsonPath('user_id', null);
    }

    public function test_leaving_keeps_accounts_as_shared_and_only_owner_can_remove_others(): void
    {
        [$helmy, $household] = $this->signUp('Helmy', 'helmy@test.dev');
        [$rika] = $this->signUp('Rika', 'rika@test.dev');
        $household->users()->attach($rika->id, ['role' => 'member']);
        $rikaAccount = $household->accounts()->create(['name' => 'Cash Rika', 'base_balance' => 0, 'user_id' => $rika->id]);

        Sanctum::actingAs($rika);
        $this->deleteJson("/api/ledgers/{$household->id}/members/{$helmy->id}")->assertForbidden();
        $this->deleteJson("/api/ledgers/{$household->id}/members/{$rika->id}")->assertOk();

        $this->assertNull($rikaAccount->fresh()->user_id);
        $this->assertFalse($household->fresh()->users->contains($rika->id));

        Sanctum::actingAs($helmy);
        $this->deleteJson("/api/ledgers/{$household->id}/members/{$helmy->id}")->assertStatus(422); // last member
    }

    public function test_assign_owners_command_is_a_dry_run_unless_applied(): void
    {
        [$helmy, $household] = $this->signUp('Helmy', 'helmy@test.dev');
        [$rika] = $this->signUp('Rika', 'rika@test.dev');
        $household->users()->attach($rika->id, ['role' => 'member']);
        $bca = $household->accounts()->create(['name' => 'BCA', 'base_balance' => 0]);
        $cashRika = $household->accounts()->create(['name' => 'Cash Rika', 'base_balance' => 0]);
        $args = ['ledger' => $household->id, '--owner' => 'helmy@test.dev', '--partner' => ['rika@test.dev:rika']];

        $this->artisan('households:assign-owners', $args)->assertSuccessful();
        $this->assertNull($cashRika->fresh()->user_id);

        $this->artisan('households:assign-owners', $args + ['--apply' => true])->assertSuccessful();
        $this->assertSame($rika->id, $cashRika->fresh()->user_id);
        $this->assertSame($helmy->id, $bca->fresh()->user_id);
    }
}
