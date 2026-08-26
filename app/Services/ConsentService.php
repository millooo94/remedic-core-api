<?php

namespace App\Services;

use App\Enums\ConsentEventType;
use App\Models\ConsentConfiguration;
use App\Models\ConsentRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConsentService
{
    /** @param array{preferences: bool, statistics: bool, marketing: bool} $preferences
     * @return array{record: ConsentRecord, management_token: string}
     */
    public function create(ConsentConfiguration $configuration, array $preferences): array
    {
        return DB::transaction(function () use ($configuration, $preferences): array {
            $now = now();
            $token = bin2hex(random_bytes(32));
            $record = ConsentRecord::query()->create([
                'consent_uuid' => (string) Str::uuid(),
                'public_id' => (string) Str::uuid(),
                'management_token_hash' => hash('sha256', $token),
                'configuration_version' => $configuration->configuration_version,
                'necessary' => true,
                ...$preferences,
                'consented_at' => $now,
                'last_updated_at' => $now,
            ]);
            $this->event($record, ConsentEventType::CREATED, $now);

            return ['record' => $record, 'management_token' => $token];
        });
    }

    /** @param array{preferences: bool, statistics: bool, marketing: bool} $preferences */
    public function update(ConsentRecord $record, ConsentConfiguration $configuration, array $preferences): ConsentRecord
    {
        return DB::transaction(function () use ($record, $configuration, $preferences): ConsentRecord {
            $record->refresh();
            $now = now();
            $renewing = $record->configuration_version !== $configuration->configuration_version;
            $hadOptionalConsent = $record->preferences || $record->statistics || $record->marketing;
            $allOptionalDenied = ! $preferences['preferences'] && ! $preferences['statistics'] && ! $preferences['marketing'];
            $eventType = $renewing
                ? ConsentEventType::RENEWED
                : ($hadOptionalConsent && $allOptionalDenied ? ConsentEventType::WITHDRAWN : ConsentEventType::UPDATED);

            $record->fill([
                'configuration_version' => $configuration->configuration_version,
                'necessary' => true,
                ...$preferences,
                'consented_at' => $renewing ? $now : $record->consented_at,
                'last_updated_at' => $now,
            ])->save();
            $this->event($record, $eventType, $now);

            return $record;
        });
    }

    private function event(ConsentRecord $record, ConsentEventType $eventType, mixed $occurredAt): void
    {
        $record->events()->create([
            'event_type' => $eventType,
            'configuration_version' => $record->configuration_version,
            'necessary' => true,
            'preferences' => $record->preferences,
            'statistics' => $record->statistics,
            'marketing' => $record->marketing,
            'occurred_at' => $occurredAt,
        ]);
    }
}
