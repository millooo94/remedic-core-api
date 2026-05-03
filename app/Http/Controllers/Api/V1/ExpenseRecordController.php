<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Expenses\ExpenseRecordQueryRequest;
use App\Http\Requests\Api\V1\Expenses\StoreExpenseRecordRequest;
use App\Http\Requests\Api\V1\Expenses\UpdateExpenseRecordRequest;
use App\Http\Resources\Api\V1\ExpenseRecordResource;
use App\Models\ExpenseRecord;
use App\Services\ExpenseService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ExpenseRecordController extends Controller
{
    public function __construct(
        private readonly ExpenseService $service,
    ) {
    }

    public function index(ExpenseRecordQueryRequest $request)
    {
        $perPage = (int) $request->integer('per_page', 20);
        $records = $this->service->baseQuery($request->filters())->paginate($perPage)->withQueryString();

        return ExpenseRecordResource::collection($records);
    }

    public function summary(ExpenseRecordQueryRequest $request): array
    {
        return $this->service->summary($request->filters());
    }

    public function store(StoreExpenseRecordRequest $request): ExpenseRecordResource
    {
        $record = $this->service->create($request->validated(), $request->user());

        return new ExpenseRecordResource($record->load(['category', 'template', 'competenceAllocations']));
    }

    public function show(ExpenseRecord $expenseRecord): ExpenseRecordResource
    {
        return new ExpenseRecordResource($expenseRecord->load(['category', 'template', 'competenceAllocations']));
    }

    public function update(UpdateExpenseRecordRequest $request, ExpenseRecord $expenseRecord): ExpenseRecordResource
    {
        $record = $this->service->update($expenseRecord, $request->validated(), $request->user());

        return new ExpenseRecordResource($record);
    }

    public function destroy(Request $request, ExpenseRecord $expenseRecord): Response
    {
        $this->service->delete($expenseRecord, $request->user());

        return response()->noContent();
    }
}
