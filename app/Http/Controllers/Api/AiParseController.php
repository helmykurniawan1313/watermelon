<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ledger;
use App\Models\UserApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiParseController extends Controller
{
    /**
     * Parse a free-text transaction description (e.g. "tambah pengeluaran 10.000 untuk bakso, di cash")
     * into a structured draft the user can review/edit before saving. Never saves anything itself.
     */
    public function parseTransaction(Request $request, Ledger $ledger)
    {
        $this->authorizeLedger($ledger);

        $validated = $request->validate([
            'text' => 'required|string|max:500',
            // "auto" (default) tries the user's keys in priority order, falling back to the
            // other providers. A specific provider name restricts the attempt to that provider's
            // key(s) first, still falling back to the others if it fails.
            'provider' => 'nullable|string|in:auto,gemini,groq,openrouter',
        ]);

        $accounts = $ledger->accounts()->get(['id', 'name'])->toArray();
        $categories = $ledger->categories()->get(['id', 'name', 'type'])->toArray();

        $prompt = $this->buildPrompt($validated['text'], $accounts, $categories);

        $attempts = [];
        $result = $this->tryUserKeys($request->user(), $prompt, $validated['provider'] ?? 'auto', $attempts)
            ?? $this->tryGemini($prompt, config('services.gemini.key'), config('services.gemini.model'), 'Gemini (default)', $attempts)
            ?? $this->tryGroq($prompt, config('services.groq.key'), config('services.groq.model'), 'Groq (default)', $attempts);

        if (!$result) {
            return response()->json([
                'error' => 'Could not parse that right now. Please fill the form manually or try again.',
                'ai_report' => $attempts,
            ], 502);
        }

        return response()->json([
            'draft' => $result,
            'ai_report' => $attempts,
        ]);
    }

    private function buildPrompt(string $text, array $accounts, array $categories): string
    {
        $accountList = collect($accounts)->map(fn ($a) => "{$a['id']}: {$a['name']}")->implode("\n");
        $categoryList = collect($categories)->map(fn ($c) => "{$c['id']}: {$c['name']} ({$c['type']})")->implode("\n");
        $today = now()->format('Y-m-d H:i:s');

        return <<<PROMPT
You extract a single financial transaction from a short user message (often Indonesian, sometimes English/mixed) and output ONLY a JSON object, no prose, no markdown fences.

Today's date/time is: {$today}

Available accounts (id: name):
{$accountList}

Available categories (id: name (type)):
{$categoryList}

Output JSON schema (all fields required, use null when unsure):
{
  "title": string,              // short human title, e.g. "Bakso"
  "amount": number,             // positive number, no currency symbol, no thousands separators
  "type": "expense" | "income" | "transfer",
  "account_id": number | null,  // best-matching id from the accounts list above, or null if no good match
  "to_account_id": number | null, // only for transfers, best-matching destination account id
  "category_id": number | null, // best-matching id from the categories list above, or null if no good match
  "date": string,               // "YYYY-MM-DD HH:MM:SS", default to today's date/time above if not specified
  "confidence": "high" | "medium" | "low" // your confidence in this extraction overall
}

Rules:
- Indonesian number format uses "." as thousands separator and "," as decimal, e.g. "10.000" means 10000, "10.000,50" means 10000.50.
- Words like "pengeluaran", "keluar", "bayar", "beli" imply type "expense". Words like "pemasukan", "masuk", "gaji", "dapat" imply type "income". Words like "transfer", "pindah" imply type "transfer".
- Match account/category names loosely (case-insensitive, partial match, common abbreviations) against the lists above. If nothing matches well, use null rather than guessing randomly.
- Never invent an id that isn't in the lists above.

User message: "{$text}"

Respond with ONLY the JSON object.
PROMPT;
    }

    /**
     * Try the current user's configured API keys, across all providers, until one succeeds.
     * When $preferredProvider isn't "auto", that provider's keys (still in the user's priority
     * order) are tried first, then the rest as fallback. Each attempt (success or failure) is
     * appended to $attempts by reference so the caller can report which AI actually did the job.
     */
    private function tryUserKeys($user, string $prompt, string $preferredProvider, array &$attempts): ?array
    {
        if (!$user) {
            return null;
        }

        $keys = $user->apiKeys()->active()->get();

        if ($preferredProvider !== 'auto') {
            $keys = $keys->sortBy(fn ($key) => $key->provider === $preferredProvider ? 0 : 1)->values();
        }

        foreach ($keys as $key) {
            $label = $key->label ?: ucfirst($key->provider);
            $result = match ($key->provider) {
                'gemini' => $this->tryGemini($prompt, $key->api_key, $key->model ?: config('services.gemini.model'), $label, $attempts),
                'groq' => $this->tryGroq($prompt, $key->api_key, $key->model ?: config('services.groq.model'), $label, $attempts),
                'openrouter' => $this->tryOpenRouter($prompt, $key->api_key, $key->model ?: 'openrouter/auto', $label, $attempts),
                default => null,
            };

            if ($result) {
                $key->forceFill(['last_used_at' => now()])->save();
                return $result;
            }

            $key->forceFill(['last_failed_at' => now()])->save();
        }

        return null;
    }

    private function tryGemini(string $prompt, ?string $apiKey, ?string $model, string $label, array &$attempts): ?array
    {
        if (!$apiKey) {
            return null;
        }

        try {
            $response = Http::timeout(15)->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}",
                [
                    'contents' => [
                        ['parts' => [['text' => $prompt]]],
                    ],
                    'generationConfig' => [
                        'temperature' => 0,
                        'responseMimeType' => 'application/json',
                    ],
                ]
            );

            if (!$response->successful()) {
                Log::warning('Gemini parse failed', ['status' => $response->status(), 'body' => $response->body()]);
                $attempts[] = $this->attemptReport('gemini', $label, $model, false);
                return null;
            }

            $text = $response->json('candidates.0.content.parts.0.text');
            $result = $this->parseJsonSafely($text);
            $attempts[] = $this->attemptReport('gemini', $label, $model, (bool) $result);
            return $result;
        } catch (\Throwable $e) {
            Log::warning('Gemini parse exception', ['message' => $e->getMessage()]);
            $attempts[] = $this->attemptReport('gemini', $label, $model, false);
            return null;
        }
    }

    private function tryGroq(string $prompt, ?string $apiKey, ?string $model, string $label, array &$attempts): ?array
    {
        if (!$apiKey) {
            return null;
        }

        try {
            $response = Http::timeout(15)
                ->withToken($apiKey)
                ->post('https://api.groq.com/openai/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('Groq parse failed', ['status' => $response->status(), 'body' => $response->body()]);
                $attempts[] = $this->attemptReport('groq', $label, $model, false);
                return null;
            }

            $text = $response->json('choices.0.message.content');
            $result = $this->parseJsonSafely($text);
            $attempts[] = $this->attemptReport('groq', $label, $model, (bool) $result);
            return $result;
        } catch (\Throwable $e) {
            Log::warning('Groq parse exception', ['message' => $e->getMessage()]);
            $attempts[] = $this->attemptReport('groq', $label, $model, false);
            return null;
        }
    }

    private function tryOpenRouter(string $prompt, ?string $apiKey, ?string $model, string $label, array &$attempts): ?array
    {
        if (!$apiKey) {
            return null;
        }

        try {
            $response = Http::timeout(15)
                ->withToken($apiKey)
                ->post('https://openrouter.ai/api/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('OpenRouter parse failed', ['status' => $response->status(), 'body' => $response->body()]);
                $attempts[] = $this->attemptReport('openrouter', $label, $model, false);
                return null;
            }

            $text = $response->json('choices.0.message.content');
            $result = $this->parseJsonSafely($text);
            $attempts[] = $this->attemptReport('openrouter', $label, $model, (bool) $result);
            return $result;
        } catch (\Throwable $e) {
            Log::warning('OpenRouter parse exception', ['message' => $e->getMessage()]);
            $attempts[] = $this->attemptReport('openrouter', $label, $model, false);
            return null;
        }
    }

    private function attemptReport(string $provider, string $label, ?string $model, bool $success): array
    {
        return [
            'provider' => $provider,
            'label' => $label,
            'model' => $model,
            'success' => $success,
        ];
    }

    private function parseJsonSafely(?string $text): ?array
    {
        if (!$text) {
            return null;
        }

        // Strip markdown code fences if the model added them despite instructions.
        $text = trim($text);
        $text = preg_replace('/^```(json)?/i', '', $text);
        $text = preg_replace('/```$/', '', $text);
        $text = trim($text);

        $decoded = json_decode($text, true);
        if (!is_array($decoded) || !isset($decoded['amount'], $decoded['type'])) {
            return null;
        }

        $typeIsValid = in_array($decoded['type'], ['expense', 'income', 'transfer'], true);

        return [
            'title' => $decoded['title'] ?? null,
            'amount' => (float) $decoded['amount'],
            'type' => $typeIsValid ? $decoded['type'] : 'expense',
            'account_id' => $decoded['account_id'] ?? null,
            'to_account_id' => $decoded['to_account_id'] ?? null,
            'category_id' => $decoded['category_id'] ?? null,
            'date' => $decoded['date'] ?? now()->format('Y-m-d H:i:s'),
            'confidence' => $typeIsValid ? ($decoded['confidence'] ?? 'medium') : 'low',
        ];
    }
}
