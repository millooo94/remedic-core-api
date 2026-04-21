<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ReminderResource;
use App\Models\Reminder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ReminderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Reminder::query()
            ->when($request->string('q')->toString(), function ($builder, string $search): void {
                $builder->where(function ($nested) use ($search): void {
                    $nested
                        ->where('title', 'like', "%{$search}%")
                        ->orWhere('recipient_email', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%")
                        ->orWhere('body', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('is_active')
            ->orderBy('title');

        return ReminderResource::collection($query->get());
    }

    public function store(Request $request): ReminderResource
    {
        $reminder = Reminder::query()->create($this->validatedPayload($request));

        return new ReminderResource($reminder);
    }

    public function update(Request $request, Reminder $reminder): ReminderResource
    {
        $reminder->fill($this->validatedPayload($request));
        $reminder->save();

        return new ReminderResource($reminder->refresh());
    }

    public function destroy(Reminder $reminder): Response
    {
        $reminder->delete();
        return response()->noContent();
    }

    private function validatedPayload(Request $request): array
    {
        $payload = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'recipient_email' => ['required', 'email', 'max:190'],
            'subject' => ['required', 'string', 'max:190'],
            'body' => ['required', 'string'],
            'frequency' => ['required', 'in:weekly,monthly,quarterly,yearly'],
            'day_of_month' => ['nullable', 'integer', 'between:1,31'],
            'day_of_week' => ['nullable', 'integer', 'between:1,7'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($payload['frequency'] === 'weekly') {
            $payload['day_of_week'] = $payload['day_of_week'] ?? 1;
            $payload['day_of_month'] = null;
        } else {
            $payload['day_of_month'] = $payload['day_of_month'] ?? 20;
            $payload['day_of_week'] = null;
        }

        return $payload;
    }
}

