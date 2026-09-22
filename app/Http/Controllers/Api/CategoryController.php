<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Ledger;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * Fetch all categories for a specific ledger.
     */
    public function index(Request $request, Ledger $ledger)
    {
        $this->authorizeLedger($ledger);

        return response()->json($ledger->categories);
    }

    /**
     * Create a new category inside a ledger.
     */
  public function store(Request $request, Ledger $ledger)
{
    $this->authorizeLedger($ledger);

    $validated = $request->validate([
        'name' => 'required|string',
        'icon' => 'required|string',
        'color' => 'required|string',
        'type' => 'nullable|in:income,expense,both',
    ]);

    // This creates the category ALREADY linked to the ledger from the URL
    $category = $ledger->categories()->create($validated + ['type' => $validated['type'] ?? 'both']);

    return response()->json($category, 201);
}

    /**
     * Update an existing category.
     */
    public function update(Request $request, Category $category)
    {
        // Check if user has access to the ledger this category belongs to
        $this->authorizeLedger($category->ledger);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'nullable|in:income,expense,both',
            'icon' => 'nullable|string',
            'color' => 'nullable|string',
        ]);

        $category->update($validated);

        return response()->json([
            'message' => 'Category updated successfully!',
            'category' => $category
        ]);
    }

    /**
     * Delete a category.
     */
    public function destroy(Request $request, Category $category)
    {
        $this->authorizeLedger($category->ledger);

        // Note: You might want to prevent deletion if transactions exist, 
        // or handle them via cascading deletes in the database.
        $category->delete();

        return response()->json([
            'message' => 'Category deleted successfully!'
        ]);
    }
}