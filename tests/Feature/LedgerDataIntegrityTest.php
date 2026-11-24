<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Ledger;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LedgerDataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Ledger $ledger;
    private Account $bca;
    private Account $gopay;
    private Category $food;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->ledger = Ledger::create(['name' => 'Test', 'currency' => 'IDR']);
        $this->ledger->users()->attach($this->user->id, ['role' => 'owner']);
        $this->bca = $this->ledger->accounts()->create(['name' => 'BCA', 'base_balance' => 1000000]);
        $this->gopay = $this->ledger->accounts()->create(['name' => 'Gopay', 'base_balance' => 0]);
        $this->food = $this->ledger->categories()->create(['name' => 'Food', 'icon' => '🍜', 'color' => '#F0782D', 'type' => 'expense']);

        Sanctum::actingAs($this->user);
    }

    private function tx(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Lunch',
            'amount' => 25000,
            'type' => 'expense',
            'date' => now()->subHour()->format('Y-m-d H:i:s'),
            'account_id' => $this->bca->id,
            'category_id' => $this->food->id,
        ], $overrides);
    }

    private function createCicilan(array $overrides = []): void
    {
        $this->postJson("/api/ledgers/{$this->ledger->id}/installments", array_merge([
            'title' => 'iPhone', 'base_price' => 1200000, 'duration_months' => 12, 'monthly_amount' => 100000,
            'interest_rate' => 0, 'start_date' => now()->format('Y-m-d H:i:s'),
            'account_id' => $this->bca->id, 'category_id' => $this->food->id, 'ledger_id' => $this->ledger->id,
        ], $overrides))->assertCreated();
    }

    public function test_transfer_to_the_same_account_is_rejected(): void
    {
        $this->postJson("/api/ledgers/{$this->ledger->id}/transactions", $this->tx([
            'type' => 'transfer', 'category_id' => null, 'to_account_id' => $this->bca->id,
        ]))->assertStatus(422)->assertJsonValidationErrors('to_account_id');
    }

    public function test_switching_a_transfer_to_an_expense_drops_the_destination_account(): void
    {
        $id = $this->postJson("/api/ledgers/{$this->ledger->id}/transactions", $this->tx([
            'type' => 'transfer', 'category_id' => null, 'to_account_id' => $this->gopay->id, 'amount' => 50000,
        ]))->assertCreated()->json('transaction.id');

        // The app sends the whole edited object back, including the old to_account_id.
        $this->putJson("/api/transactions/{$id}", $this->tx(['amount' => 50000, 'to_account_id' => $this->gopay->id]))->assertOk();

        $tx = Transaction::find($id);
        $this->assertSame('expense', $tx->type);
        $this->assertNull($tx->to_account_id);
        $this->assertEquals($this->food->id, $tx->category_id);
    }

    public function test_amount_must_be_positive_and_date_valid(): void
    {
        $id = $this->postJson("/api/ledgers/{$this->ledger->id}/transactions", $this->tx())->assertCreated()->json('transaction.id');

        $this->putJson("/api/transactions/{$id}", $this->tx(['amount' => -5000]))->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->postJson("/api/ledgers/{$this->ledger->id}/transactions", $this->tx(['amount' => 0]))->assertStatus(422);
        $this->postJson("/api/ledgers/{$this->ledger->id}/transactions", $this->tx(['date' => 'not a date']))->assertStatus(422)->assertJsonValidationErrors('date');
    }

    public function test_deleting_a_category_makes_its_transactions_uncategorized(): void
    {
        $id = $this->postJson("/api/ledgers/{$this->ledger->id}/transactions", $this->tx())->assertCreated()->json('transaction.id');

        $this->deleteJson("/api/categories/{$this->food->id}")->assertOk();

        $this->assertNull(Transaction::find($id)->category_id);
    }

    public function test_a_category_used_by_a_cicilan_cannot_be_deleted(): void
    {
        $this->createCicilan();

        $this->deleteJson("/api/categories/{$this->food->id}")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'iPhone'));
        $this->assertNotNull(Category::find($this->food->id));
    }

    public function test_an_account_used_by_a_cicilan_cannot_be_deleted(): void
    {
        $this->createCicilan();

        $this->deleteJson("/api/accounts/{$this->bca->id}")->assertStatus(422);
        $this->assertNotNull(Account::find($this->bca->id));
    }

    public function test_category_monthly_budget_can_be_set_and_cleared(): void
    {
        $payload = ['name' => 'Food', 'type' => 'expense', 'icon' => '🍜', 'color' => '#F0782D'];

        $this->putJson("/api/categories/{$this->food->id}", $payload + ['monthly_budget' => 500000])->assertOk();
        $this->assertEquals(500000, $this->food->fresh()->monthly_budget);

        $this->putJson("/api/categories/{$this->food->id}", $payload + ['monthly_budget' => null])->assertOk();
        $this->assertNull($this->food->fresh()->monthly_budget);

        $this->putJson("/api/categories/{$this->food->id}", $payload + ['monthly_budget' => -1])->assertStatus(422);
    }

    public function test_cicilan_needs_at_least_one_month(): void
    {
        $this->postJson("/api/ledgers/{$this->ledger->id}/installments", [
            'title' => 'Bad', 'base_price' => 100, 'duration_months' => 0, 'monthly_amount' => 100,
            'start_date' => now()->format('Y-m-d'), 'account_id' => $this->bca->id, 'category_id' => $this->food->id, 'ledger_id' => $this->ledger->id,
        ])->assertStatus(422)->assertJsonValidationErrors('duration_months');
    }

    public function test_ai_draft_drops_ids_that_are_not_in_the_ledger(): void
    {
        $this->user->apiKeys()->create(['provider' => 'gemini', 'api_key' => 'test-key', 'priority' => 0, 'is_active' => true]);

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode([
                'title' => 'Bakso', 'amount' => -10000, 'type' => 'expense',
                'account_id' => 99999, 'to_account_id' => null, 'category_id' => $this->food->id,
                'date' => 'tomorrow-ish', 'confidence' => 'high',
            ])]]]]],
        ])]);

        $draft = $this->postJson("/api/ledgers/{$this->ledger->id}/parse-transaction", ['text' => 'bakso 10rb'])
            ->assertOk()->json('draft');

        $this->assertNull($draft['account_id']);
        $this->assertSame($this->food->id, $draft['category_id']);
        $this->assertEquals(10000, $draft['amount']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $draft['date']);

        // The key travels in a header, never in the URL.
        Http::assertSent(fn ($request) => $request->hasHeader('x-goog-api-key', 'test-key') && !str_contains($request->url(), 'key='));
    }

    public function test_category_routes_without_a_ledger_are_not_exposed(): void
    {
        $this->getJson("/api/categories/{$this->food->id}")->assertStatus(405);
    }

    public function test_account_logo_can_be_chosen_or_uploaded(): void
    {
        $base = ['name' => 'BCA', 'balance' => 1000000, 'color' => '#1F5FD6'];
        $png = 'data:image/png;base64,' . base64_encode(str_repeat('x', 4000));

        foreach (['logo:bca', 'badge', 'auto', $png] as $icon) {
            $this->putJson("/api/accounts/{$this->bca->id}", $base + ['icon' => $icon])->assertOk();
            $this->assertSame($icon, $this->bca->fresh()->icon);
        }

        $svg = 'data:image/svg+xml;base64,' . base64_encode('<svg onload="alert(1)"/>');
        $this->putJson("/api/accounts/{$this->bca->id}", $base + ['icon' => $svg])->assertStatus(422);
    }
}
