<?php

namespace App\Http\Requests\Api\V1\DailyBookingStats;

use App\Models\DailyBookingStat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpsertDailyBookingStatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAccessPrivateDashboard() ?? false;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'bookings_count' => ['required', 'integer', 'min:0'],
            'cancellations_count' => ['required', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $date = $this->input('date');
            if ($this->isMethod('post') && is_string($date) && DailyBookingStat::query()->whereDate('date', $date)->exists()) {
                $validator->errors()->add('date', 'Esiste già una registrazione per questa data.');
            }
        });
    }
}
