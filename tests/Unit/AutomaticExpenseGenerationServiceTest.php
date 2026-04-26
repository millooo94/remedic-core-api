<?php

namespace Tests\Unit;

use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\ExpenseTemplate;
use App\Services\AutomaticExpenseGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AutomaticExpenseGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_monthly_cost_and_prevents_duplicates(): void
    {
        $category = ExpenseCategory::factory()->create([
            'is_active' => true,
        ]);

        $template = ExpenseTemplate::query()->create([
            'category_id' => $category->id,
            'name' => 'Affitto sede',
            'type' => 'fixed',
            'recurrence' => 'monthly',
            'default_amount' => 2000,
            'start_date' => '2026-04-01',
            'day_of_generation' => 1,
            'is_active' => true,
        ]);

        $service = app(AutomaticExpenseGenerationService::class);

        $first = $service->generateDue(Carbon::parse('2026-04-03'));
        $this->assertSame(1, $first['generated']);

        $second = $service->generateDue(Carbon::parse('2026-04-03'));
        $this->assertSame(0, $second['generated']);
        $this->assertSame(1, $second['skipped_duplicates']);

        $this->assertDatabaseHas('expense_records', [
            'expense_template_id' => $template->id,
            'expense_date' => '2026-04-01 00:00:00',
            'source' => 'automatic',
            'generation_key' => "expense-template:{$template->id}:2026-04-01",
            'payment_status' => 'pagata',
        ]);

        $this->assertEquals(1, ExpenseRecord::query()->where('expense_template_id', $template->id)->count());
    }
}
