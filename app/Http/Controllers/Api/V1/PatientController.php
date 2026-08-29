<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PatientResource;
use App\Models\Patient;
use App\Services\Marketing\ItalianTaxCodeService;
use App\Services\PatientImportService;
use App\Services\PatientService;
use App\Support\ItalianProvinces;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PatientController extends Controller
{
    public function __construct(
        private readonly PatientService $service,
        private readonly PatientImportService $importService,
        private readonly ItalianTaxCodeService $taxCodeService,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->normalizeBooleanQuery($request, [
            'excluded_from_campaigns',
            'contactable_sms',
            'contactable_whatsapp',
            'contactable_email',
            'only_without_history',
        ]);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:190'],
            'excluded_from_campaigns' => ['nullable', 'boolean'],
            'contactable_sms' => ['nullable', 'boolean'],
            'contactable_whatsapp' => ['nullable', 'boolean'],
            'contactable_email' => ['nullable', 'boolean'],
            'area_name' => ['nullable', 'string', 'max:120'],
            'only_without_history' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $perPage = (int) ($filters['per_page'] ?? 20);
        $patients = $this->service->baseQuery($filters)->paginate($perPage)->withQueryString();
        $this->service->hydrateCollection($patients->getCollection());

        return PatientResource::collection($patients);
    }

    public function options(Request $request)
    {
        $search = trim((string) $request->string('q')->toString());

        $patients = $this->service->baseQuery([
            'q' => $search !== '' ? $search : null,
            // Without a search term, prioritize recently created/updated patients
            // so newly inserted entries appear immediately in selection lists.
            'sort' => $search !== '' ? 'full_name' : '-updated_at',
            'per_page' => 2000,
        ])->limit(2000)->get();
        $this->service->hydrateCollection($patients);

        return PatientResource::collection($patients);
    }

    public function store(Request $request): PatientResource
    {
        $payload = $this->validatedPayload($request);
        $patient = $this->service->create($payload, $request->user());

        return new PatientResource($patient);
    }

    public function show(Patient $patient): PatientResource
    {
        return new PatientResource($this->service->detail($patient));
    }

    public function update(Request $request, Patient $patient): PatientResource
    {
        $payload = $this->validatedPayload($request);
        $updated = $this->service->update($patient, $payload, $request->user());

        return new PatientResource($updated);
    }

    public function destroy(Patient $patient): Response
    {
        $this->service->delete($patient);

        return response()->noContent();
    }

    public function import(Request $request): array
    {
        $payload = $request->validate([
            'file' => ['required', 'file', 'mimes:xml'],
            'update_existing' => ['nullable', 'boolean'],
        ]);

        return $this->importService->import(
            file: $request->file('file'),
            actor: $request->user(),
            updateExisting: (bool) ($payload['update_existing'] ?? true),
        );
    }

    private function validatedPayload(Request $request): array
    {
        if ($request->has('residence_province')) {
            $normalizedProvince = ItalianProvinces::normalize($request->input('residence_province'));
            if ($normalizedProvince !== null || blank($request->input('residence_province'))) {
                $request->merge(['residence_province' => $normalizedProvince]);
            }
        }

        return $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'sex' => ['nullable', 'in:male,female,other'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'year_of_birth' => ['nullable', 'integer', 'between:1900,'.now()->year],
            'tax_code' => [
                'nullable',
                'string',
                'size:16',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $value) {
                        return;
                    }

                    if (! $this->taxCodeService->isPlausible((string) $value)) {
                        $fail('Il codice fiscale non e valido.');

                        return;
                    }

                    if (! $this->taxCodeService->extractBirthDate((string) $value)) {
                        $fail('Il codice fiscale non contiene una data di nascita valida.');
                    }
                },
            ],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
            'residence_address' => ['nullable', 'string', 'max:190'],
            'residence_city' => ['nullable', 'string', 'max:120'],
            'residence_province' => ['nullable', 'string', 'size:2', 'in:'.implode(',', ItalianProvinces::codes())],
            'residence_zip' => ['nullable', 'string', 'max:10', 'regex:/^\d{5}$/'],
            'contactable_sms' => ['sometimes', 'boolean'],
            'contactable_whatsapp' => ['sometimes', 'boolean'],
            'contactable_email' => ['sometimes', 'boolean'],
            'excluded_from_campaigns' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    private function normalizeBooleanQuery(Request $request, array $keys): void
    {
        foreach ($keys as $key) {
            if (! $request->has($key)) {
                continue;
            }

            $normalized = filter_var($request->query($key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($normalized !== null) {
                $request->merge([$key => $normalized]);
            }
        }
    }
}
