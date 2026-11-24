<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Installment;
use App\Models\Ledger;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        'monthly_budget' => 'nullable|numeric|min:0',
    ]);

    // This creates the category ALREADY linked to the ledger from the URL
    $category = $ledger->categories()->create($validated + ['type' => $validated['type'] ?? 'both']);
    ActivityLog::record($ledger->id, 'created', 'category', $category->id, "added category {$category->name}");

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
            'monthly_budget' => 'nullable|numeric|min:0',
        ]);

        $category->update($validated);
        ActivityLog::record($category->ledger_id, 'updated', 'category', $category->id, "edited category {$category->name}");

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

        // Cicilan plans reference their category without cascading; deleting would fail.
        $plans = Installment::where('category_id', $category->id)->pluck('title');
        if ($plans->isNotEmpty()) {
            return response()->json([
                'message' => 'This category is used by cicilan: ' . $plans->implode(', ') . '. Change or delete the cicilan first.',
            ], 422);
        }

        // transactions.category_id has no foreign key, so without this the transactions
        // would keep pointing at a category that no longer exists. Make them uncategorized.
        DB::transaction(function () use ($category) {
            Transaction::where('category_id', $category->id)->update(['category_id' => null]);
            ActivityLog::record($category->ledger_id, 'deleted', 'category', $category->id, "deleted category {$category->name}");
            $category->delete();
        });

        return response()->json([
            'message' => 'Category deleted successfully!'
        ]);
    }
}