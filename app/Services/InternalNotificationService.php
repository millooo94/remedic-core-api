<?php

namespace App\Services;

use App\Models\InternalNotification;
use App\Models\User;
use App\Notifications\InternalNotificationPayload;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

class InternalNotificationService
{
    public function __construct(private readonly InternalNotificationRecipientResolver $recipients) {}

    public function notifyUser(User $recipient, InternalNotificationPayload $payload): InternalNotification
    {
        $attributes = ['recipient_user_id' => $recipient->id, ...$payload->attributes()];

        if ($payload->deduplicationKey === null) {
            return InternalNotification::query()->create($attributes);
        }

        return InternalNotification::query()->firstOrCreate(
            ['recipient_user_id' => $recipient->id, 'deduplication_key' => $payload->deduplicationKey],
            $attributes,
        );
    }

    /** @param iterable<User> $recipients @return Collection<int, InternalNotification> */
    public function notifyUsers(iterable $recipients, InternalNotificationPayload $payload): Collection
    {
        return collect($recipients)
            ->filter(fn (mixed $recipient): bool => $recipient instanceof User)
            ->unique('id')
            ->map(fn (User $recipient): InternalNotification => $this->notifyUser($recipient, $payload))
            ->values();
    }

    /** @return Collection<int, InternalNotification> */
    public function notifyUsersWithPermission(string $permission, InternalNotificationPayload $payload): Collection
    {
        return $this->notifyUsers($this->recipients->forPermission($permission), $payload);
    }

    public function markAsRead(User $recipient, string $publicId): InternalNotification
    {
        $notification = InternalNotification::query()
            ->where('recipient_user_id', $recipient->id)
            ->where('public_id', $publicId)
            ->first();

        if ($notification === null) {
            throw (new ModelNotFoundException)->setModel(InternalNotification::class, [$publicId]);
        }

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return $notification;
    }

    public function markAllAsRead(User $recipient, ?string $context = null): int
    {
        return InternalNotification::query()
            ->where('recipient_user_id', $recipient->id)
            ->whereNull('read_at')
            ->when($context !== null, fn ($query) => $query->where('context', $context))
            ->update(['read_at' => now()]);
    }

    /** @return array{unread_count:int,context_counts:array<string,int>} */
    public function summary(User $recipient): array
    {
        $unread = InternalNotification::query()->where('recipient_user_id', $recipient->id)->whereNull('read_at');

        return [
            'unread_count' => (clone $unread)->count(),
            'context_counts' => (clone $unread)->selectRaw('context, count(*) as aggregate')->groupBy('context')->pluck('aggregate', 'context')->map(fn (int $count): int => $count)->all(),
        ];
    }
}
