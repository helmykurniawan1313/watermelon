<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserApiKey;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserApiKeyController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            $request->user()->apiKeys()->get()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'provider' => ['required', Rule::in(UserApiKey::PROVIDERS)],
            'label' => ['nullable', 'string', 'max:100'],
            'api_key' => ['required', 'string', 'max:500'],
            'model' => ['nullable', 'string', 'max:100'],
            'priority' => ['nullable', 'integer', 'min:0'],
        ]);

        $key = $request->user()->apiKeys()->create($validated);

        return response()->json($key, 201);
    }

    public function update(Request $request, UserApiKey $apiKey)
    {
        $this->authorizeOwner($request, $apiKey);

        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:100'],
            'api_key' => ['sometimes', 'string', 'max:500'],
            'model' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0'],
        ]);

        $apiKey->update($validated);

        return response()->json($apiKey);
    }

    public function destroy(Request $request, UserApiKey $apiKey)
    {
        $this->authorizeOwner($request, $apiKey);

        $apiKey->delete();

        return response()->json(null, 204);
    }

    private function authorizeOwner(Request $request, UserApiKey $apiKey): void
    {
        abort_if($apiKey->user_id !== $request->user()->id, 403);
    }
}
