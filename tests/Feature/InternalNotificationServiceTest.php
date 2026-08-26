<?php

namespace Tests\Feature;

use App\Enums\AdminPermission;
use App\Enums\NotificationSeverity;
use App\Models\InternalNotification;
use App\Models\User;
use App\Notifications\InternalNotificationAction;
use App\Notifications\InternalNotificationPayload;
use App\Services\InternalNotificationService;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class InternalNotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
    }

    public function test_it_notifies_single_multiple_and_permission_resolved_recipients_with_safe_payloads(): void
    {
        $first = $this->user();
        $second = $this->user();
        $second->givePermissionTo(AdminPermission::MANAGE_SEARCH->value);
        $service = app(InternalNotificationService::class);
        $payload = new InternalNotificationPayload('configuration_attention', 'system', 'Configurazione', 'Controlla la configurazione.', NotificationSeverity::WARNING, new InternalNotificationAction('settings', ['tab' => 'general']), 'configuration', '87dff3ce-9e67-4de3-85ae-9c1ecfecc176');

        $single = $service->notifyUser($first, $payload);
        $multiple = $service->notifyUsers([$first, $second, $first], $payload);
        $permissionRecipients = $service->notifyUsersWithPermission(AdminPermission::MANAGE_SEARCH->value, $payload);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $single->public_id);
        $this->assertSame('warning', $single->severity->value);
        $this->assertSame(['route' => 'settings', 'params' => ['tab' => 'general']], $single->action);
        $this->assertCount(2, $multiple);
        $this->assertCount(1, $permissionRecipients);
        $this->assertSame($second->id, $permissionRecipients->first()->recipient_user_id);
        $this->assertDatabaseCount('internal_notifications', 4);
    }

    public function test_deduplication_is_scoped_to_each_recipient_and_allows_repeated_events_without_a_key(): void
    {
        $first = $this->user();
        $second = $this->user();
        $service = app(InternalNotificationService::class);
        $deduplicated = new InternalNotificationPayload('system_warning', 'system', 'Attenzione', 'Messaggio', deduplicationKey: 'retry:42');
        $unkeyed = new InternalNotificationPayload('system_warning', 'system', 'Attenzione', 'Messaggio');

        $firstNotification = $service->notifyUser($first, $deduplicated);
        $this->assertSame($firstNotification->id, $service->notifyUser($first, $deduplicated)->id);
        $service->notifyUser($second, $deduplicated);
        $service->notifyUser($first, $unkeyed);
        $service->notifyUser($first, $unkeyed);

        $this->assertSame(4, InternalNotification::query()->count());
    }

    public function test_only_allowlisted_internal_actions_can_be_constructed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new InternalNotificationAction('https://outside.example');
    }

    public function test_invalid_payloads_cannot_create_unbounded_contexts_or_partial_source_references(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new InternalNotificationPayload('configuration-attention', 'system', 'Titolo', 'Messaggio', sourceType: 'configuration');
    }

    private function user(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(AdminPermission::VIEW_BACKOFFICE->value);

        return $user;
    }
}
