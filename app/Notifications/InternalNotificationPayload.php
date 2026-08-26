<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use InvalidArgumentException;

final readonly class InternalNotificationPayload
{
    public function __construct(
        public string $kind,
        public string $context,
        public string $title,
        public string $message,
        public NotificationSeverity $severity = NotificationSeverity::INFO,
        public ?InternalNotificationAction $action = null,
        public ?string $sourceType = null,
        public ?string $sourcePublicId = null,
        public ?string $deduplicationKey = null,
    ) {
        foreach (['kind' => $kind, 'context' => $context] as $field => $value) {
            if (! preg_match('/^[a-z][a-z0-9_]{0,99}$/', $value)) {
                throw new InvalidArgumentException("The notification {$field} is invalid.");
            }
        }
        if (trim($title) === '' || mb_strlen($title) > 180 || trim($message) === '' || mb_strlen($message) > 500) {
            throw new InvalidArgumentException('The notification copy is invalid.');
        }
        if (($sourceType === null) !== ($sourcePublicId === null)) {
            throw new InvalidArgumentException('A notification source reference must be complete.');
        }
        if ($deduplicationKey !== null && (trim($deduplicationKey) === '' || mb_strlen($deduplicationKey) > 191)) {
            throw new InvalidArgumentException('The notification deduplication key is invalid.');
        }
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return [
            'kind' => $this->kind,
            'context' => $this->context,
            'title' => $this->title,
            'message' => $this->message,
            'severity' => $this->severity,
            'action' => $this->action?->toArray(),
            'source_type' => $this->sourceType,
            'source_public_id' => $this->sourcePublicId,
            'deduplication_key' => $this->deduplicationKey,
        ];
    }
}
