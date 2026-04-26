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
        $request->merge([
            'default_amount' => is_string($request->input('default_amount'))
                ? str_replace(',', '.', trim((string) $request->input('default_amount')))
                : $request->input('default_amount'),
        ]);

        $payload = $request->validate([
            'category_id' => ['required', 'exists:expense_categories,id'],
            'name' => ['required', 'string', 'max:190'],
            'recurrence' => ['required', 'in:weekly,monthly,bimonthly,quarterly,yearly,manual'],
            'default_amount' => ['required', 'numeric', 'min:0.01'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'day_of_generation' => ['nullable', 'integer', 'between:1,31'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);
        $payload['default_amount'] = $this->normalizeMoneyInput($payload['default_amount'] ?? null);

        if (($payload['recurrence'] ?? null) === 'weekly' && isset($payload['day_of_generation'])) {
            $payload['day_of_generation'] = max(1, min(7, (int) $payload['day_of_generation']));
        }
        $payload['type'] = 'fixed';
        $payload['start_date'] = $payload['start_date'] ?? now()->toDateString();

        $template = ExpenseTemplate::query()->create($payload);

        return new ExpenseTemplateResource($template->load('category'));
    }

    public function update(Request $request, ExpenseTemplate $expenseTemplate): ExpenseTemplateResource
    {
        $request->merge([
            'default_amount' => is_string($request->input('default_amount'))
                ? str_replace(',', '.', trim((string) $request->input('default_amount')))
                : $request->input('default_amount'),
        ]);

        $payload = $request->validate([
            'category_id' => ['required', 'exists:expense_categories,id'],
            'name' => ['required', 'string', 'max:190'],
            'recurrence' => ['required', 'in:weekly,monthly,bimonthly,quarterly,yearly,manual'],
            'default_amount' => ['required', 'numeric', 'min:0.01'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'day_of_generation' => ['nullable', 'integer', 'between:1,31'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);
        $payload['default_amount'] = $this->normalizeMoneyInput($payload['default_amount'] ?? null);

        if (($payload['recurrence'] ?? null) === 'weekly' && isset($payload['day_of_generation'])) {
            $payload['day_of_generation'] = max(1, min(7, (int) $payload['day_of_generation']));
        }
        $payload['type'] = 'fixed';

        $expenseTemplate->fill($payload);
        $expenseTemplate->save();

        return new ExpenseTemplateResource($expenseTemplate->refresh()->load('category'));
    }

    private function normalizeMoneyInput(mixed $value): string
    {
        $normalized = is_string($value)
            ? str_replace(',', '.', trim($value))
            : $value;

        $parsed = (float) $normalized;

        return number_format(max(0.01, $parsed), 2, '.', '');
    }

    public function destroy(ExpenseTemplate $expenseTemplate): Response
    {
        $expenseTemplate->delete();

        return response()->noContent();
    }
}
