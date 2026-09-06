<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DailyBookingStats\UpsertDailyBookingStatRequest;
use App\Http\Resources\Api\V1\DailyBookingStatResource;
use App\Models\User;
use App\Services\DailyBookingStatsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DailyBookingStatController extends Controller
{
    public function __construct(private readonly DailyBookingStatsService $service) {}

    public function index(Request $request)
    {
        $validated = $request->validate(['per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])]]);

        return DailyBookingStatResource::collection($this->service->history($validated['per_page'] ?? 25));
    }

    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d', 'required_with:date_to'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'required_with:date_from', 'after_or_equal:date_from'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'between:1,12', 'required_with:year'],
        ]);
        [$start, $end] = $this->resolvePeriod($validated);

        return response()->json(['data' => $this->service->summary($start, $end)]);
    }

    public function pending(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->service->pendingFor($user)]);
    }

    public function show(string $date): DailyBookingStatResource
    {
        return new DailyBookingStatResource($this->service->find($this->validatedDate($date)));
    }

    public function store(UpsertDailyBookingStatRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return (new DailyBookingStatResource($this->service->create($user, $request->validated())))->response()->setStatusCode(201);
    }

    public function update(UpsertDailyBookingStatRequest $request, string $date): DailyBookingStatResource
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();
        unset($validated['date']);

        return new DailyBookingStatResource($this->service->update($user, $this->validatedDate($date), [...$validated, 'date' => $date]));
    }

    private function validatedDate(string $date): string
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed->toDateString() !== $date) {
            throw ValidationException::withMessages(['date' => ['The date must use the Y-m-d format.']]);
        }

        return $parsed->toDateString();
    }

    /** @param array<string,mixed> $filters @return array{string,string} */
    private function resolvePeriod(array $filters): array
    {
        if (isset($filters['date_from'], $filters['date_to'])) {
            return [$filters['date_from'], $filters['date_to']];
        }

        $now = CarbonImmutable::now('Europe/Rome');
        $month = (int) ($filters['month'] ?? $now->month);
        $year = (int) ($filters['year'] ?? $now->year);
        $period = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'Europe/Rome');

        return [$period->startOfMonth()->toDateString(), $period->endOfMonth()->toDateString()];
    }
}
