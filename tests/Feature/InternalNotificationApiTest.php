<?php

namespace Tests\Feature;

use App\Enums\AdminPermission;
use App\Models\User;
use App\Notifications\InternalNotificationPayload;
use App\Services\InternalNotificationService;
use Database\Seeders\BackofficeAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InternalNotificationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BackofficeAccessSeeder::class);
    }

    public function test_notification_api_requires_authentication_and_keeps_each_recipient_isolated(): void
    {
        $first = $this->user();
        $second = $this->user();
        $service = app(InternalNotificationService::class);
        $firstNotification = $service->notifyUser($first, $this->payload('alpha'));
        $secondNotification = $service->notifyUser($second, $this->payload('beta'));

        $this->getJson('/api/v1/admin/notifications')->assertUnauthorized();
        Sanctum::actingAs($first);
        $this->getJson('/api/v1/admin/notifications')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $firstNotification->public_id)
            ->assertJsonMissingPath('data.0.recipient_user_id')
            ->assertJsonMissingPath('data.0.source_type')
            ->assertJsonMissingPath('data.0.deduplication_key');
        $this->patchJson('/api/v1/admin/notifications/'.$secondNotification->public_id.'/read')->assertNotFound();
    }

    public function test_list_summary_and_read_operations_are_bounded_idempotent_and_context_aware(): void
    {
        $user = $this->user();
        $service = app(InternalNotificationService::class);
        $alphaOne = $service->notifyUser($user, $this->payload('alpha'));
        $service->notifyUser($user, $this->payload('alpha'));
        $service->notifyUser($user, $this->payload('beta'));
        $service->notifyUser($user, $this->payload('beta'));
        $service->notifyUser($user, $this->payload('beta'));
        $read = $service->notifyUser($user, $this->payload('alpha'));
        $service->markAsRead($user, $read->public_id);

        Sanctum::actingAs($user);
        $this->getJson('/api/v1/admin/notifications/summary')->assertOk()
            ->assertJsonPath('data.unread_count', 5)
            ->assertJsonPath('data.context_counts.alpha', 2)
            ->assertJsonPath('data.context_counts.beta', 3);
        $this->getJson('/api/v1/admin/notifications?filter=unread&context=alpha&per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 1);
        $this->getJson('/api/v1/admin/notifications?filter=unknown')->assertUnprocessable();
        $this->getJson('/api/v1/admin/notifications?per_page=51')->assertUnprocessable();
        $this->patchJson('/api/v1/admin/notifications/'.$alphaOne->public_id.'/read')->assertOk()->assertJsonPath('data.is_read', true);
        $this->patchJson('/api/v1/admin/notifications/'.$alphaOne->public_id.'/read')->assertOk()->assertJsonPath('data.is_read', true);
        $this->postJson('/api/v1/admin/notifications/mark-all-read', ['context' => 'beta'])->assertOk()
            ->assertJsonPath('data.marked_count', 3)
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonPath('data.context_counts.alpha', 1);
        $this->postJson('/api/v1/admin/notifications/mark-all-read')->assertOk()
            ->assertJsonPath('data.unread_count', 0)
            ->assertJsonPath('data.context_counts', []);
    }

    private function user(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(AdminPermission::VIEW_BACKOFFICE->value);

        return $user;
    }

    private function payload(string $context): InternalNotificationPayload
    {
        return new InternalNotificationPayload('configuration_attention', $context, 'Titolo', 'Messaggio');
    }
}
