<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ExpenseTemplateResource;
use App\Models\ExpenseTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ExpenseTemplateController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ExpenseTemplateResource::collection(
            ExpenseTemplate::query()
                ->with('category')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(),
        );
    }

    public function store(Request $request): ExpenseTemplateResource
    {
        $payload = $request->validate([
            'category_id' => ['required', 'exists:expense_categories,id'],
            'name' => ['required', 'string', 'max:190'],
            'type' => ['required', 'in:fixed,variable'],
            'recurrence' => ['required', 'in:weekly,monthly,bimonthly,quarterly,yearly,manual'],
            'default_amount' => ['required', 'numeric', 'gt:0'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'day_of_generation' => ['nullable', 'integer', 'between:1,31'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        if (($payload['recurrence'] ?? null) === 'weekly' && isset($payload['day_of_generation'])) {
            $payload['day_of_generation'] = max(1, min(7, (int) $payload['day_of_generation']));
        }
        $payload['start_date'] = $payload['start_date'] ?? now()->toDateString();

        $template = ExpenseTemplate::query()->create($payload);

        return new ExpenseTemplateResource($template->load('category'));
    }

    public function update(Request $request, ExpenseTemplate $expenseTemplate): ExpenseTemplateResource
    {
        $payload = $request->validate([
            'category_id' => ['required', 'exists:expense_categories,id'],
            'name' => ['required', 'string', 'max:190'],
            'type' => ['required', 'in:fixed,variable'],
            'recurrence' => ['required', 'in:weekly,monthly,bimonthly,quarterly,yearly,manual'],
            'default_amount' => ['required', 'numeric', 'gt:0'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'day_of_generation' => ['nullable', 'integer', 'between:1,31'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        if (($payload['recurrence'] ?? null) === 'weekly' && isset($payload['day_of_generation'])) {
            $payload['day_of_generation'] = max(1, min(7, (int) $payload['day_of_generation']));
        }

        $expenseTemplate->fill($payload);
        $expenseTemplate->save();

        return new ExpenseTemplateResource($expenseTemplate->refresh()->load('category'));
    }

    public function destroy(ExpenseTemplate $expenseTemplate): Response
    {
        $expenseTemplate->delete();

        return response()->noContent();
    }
}
