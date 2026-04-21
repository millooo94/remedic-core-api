<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ExpenseCategoryResource;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class ExpenseCategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ExpenseCategoryResource::collection(
            ExpenseCategory::query()
                ->withCount(['records', 'templates'])
                ->orderByDesc('is_active')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        );
    }

    public function store(Request $request): ExpenseCategoryResource
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:fixed,variable'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $category = ExpenseCategory::query()->create([
            'name' => trim($payload['name']),
            'slug' => Str::slug($payload['name']),
            'type' => $payload['type'],
            'is_active' => $payload['is_active'] ?? true,
            'sort_order' => $payload['sort_order'] ?? null,
        ]);

        return new ExpenseCategoryResource($category);
    }

    public function update(Request $request, ExpenseCategory $expenseCategory): ExpenseCategoryResource
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:fixed,variable'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $expenseCategory->fill([
            'name' => trim($payload['name']),
            'slug' => Str::slug($payload['name']),
            'type' => $payload['type'],
            'is_active' => $payload['is_active'] ?? true,
            'sort_order' => $payload['sort_order'] ?? null,
        ]);
        $expenseCategory->save();

        return new ExpenseCategoryResource($expenseCategory->refresh());
    }

    public function destroy(ExpenseCategory $expenseCategory): Response
    {
        if ($expenseCategory->records()->exists() || $expenseCategory->templates()->exists()) {
            $expenseCategory->is_active = false;
            $expenseCategory->save();

            return response()->noContent();
        }

        $expenseCategory->delete();

        return response()->noContent();
    }
}
