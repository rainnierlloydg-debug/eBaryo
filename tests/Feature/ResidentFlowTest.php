<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\ResidentNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResidentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_resident_can_save_name_email_and_contact_number(): void
    {
        $resident = User::factory()->create(['account_status' => 'active']);
        $this->actingAs($resident)->patchJson('/api/profile', [
            'name' => 'Juan Dela Cruz', 'email' => 'juan@example.test', 'phone' => '09123456789',
        ])->assertOk()->assertJsonPath('name', 'Juan Dela Cruz')->assertJsonPath('email', 'juan@example.test');
        $this->assertDatabaseHas('users', ['id' => $resident->id, 'phone' => '09123456789']);
    }

    public function test_invalid_profile_fields_do_not_save_any_changes(): void
    {
        $resident = User::factory()->create(['account_status' => 'active', 'name' => 'Original Name']);
        $other = User::factory()->create();
        $this->actingAs($resident)->patchJson('/api/profile', [
            'name' => '', 'email' => $other->email, 'phone' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'phone']);
        $this->assertSame('Original Name', $resident->fresh()->name);
    }

    public function test_settings_load_saved_preferences_and_preserve_the_other_preference(): void
    {
        $resident = User::factory()->create([
            'account_status' => 'active', 'notification_preferences' => ['email' => false, 'in_app' => false],
        ]);
        $this->actingAs($resident)->getJson('/api/profile')->assertOk()
            ->assertJsonPath('notification_preferences.email', false)->assertJsonPath('notification_preferences.in_app', false);
        $this->patchJson('/api/notification-preferences', ['preferences' => ['email' => true]])->assertOk()
            ->assertJsonPath('preferences.email', true)->assertJsonPath('preferences.in_app', false);
    }

    public function test_password_validation_checks_current_password_before_saving(): void
    {
        $resident = User::factory()->create(['account_status' => 'active']);
        $this->actingAs($resident)->putJson('/api/password', [
            'current_password' => 'incorrect', 'password' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson('/api/password', [
            'current_password' => 'password', 'password' => 'NewSecurePass123!', 'password_confirmation' => 'Mismatch123!',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check('password', $resident->fresh()->password));
        $this->putJson('/api/password', [
            'current_password' => 'password', 'password' => 'NewSecurePass123!', 'password_confirmation' => 'NewSecurePass123!',
        ])->assertOk();
        $this->assertTrue(Hash::check('NewSecurePass123!', $resident->fresh()->password));
    }

    public function test_mark_all_as_read_only_updates_the_signed_in_residents_notifications(): void
    {
        $resident = User::factory()->create(['account_status' => 'active']);
        $other = User::factory()->create();
        foreach ([$resident, $other] as $user) {
            ResidentNotification::create(['user_id' => $user->id, 'title' => 'Request update', 'message' => 'Ready']);
        }
        $this->actingAs($resident)->getJson('/api/dashboard')->assertOk()->assertJsonPath('total_requests', 0)
            ->assertJsonPath('unread_notifications', 1);
        $this->patchJson('/api/notifications/read-all')->assertOk();
        $this->assertNotNull($resident->residentNotifications()->first()->read_at);
        $this->assertNull($other->residentNotifications()->first()->read_at);
    }

    public function test_document_search_only_returns_active_documents_with_matching_names(): void
    {
        $resident = User::factory()->create(['account_status' => 'active']);
        Document::create(['name' => 'Clearance', 'slug' => 'clearance', 'is_active' => true]);
        Document::create(['name' => 'Other', 'slug' => 'other', 'description' => 'Clearance', 'is_active' => true]);
        Document::create(['name' => 'Clearance inactive', 'slug' => 'inactive', 'is_active' => false]);
        $this->actingAs($resident)->getJson('/api/documents?search=Clearance')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Clearance');
        $this->getJson('/api/documents?search=Missing')->assertOk()->assertExactJson([]);
    }

    public function test_request_requires_purpose_and_additional_information(): void
    {
        $resident = User::factory()->create(['account_status' => 'active']);
        $document = Document::create(['name' => 'Clearance', 'slug' => 'clearance', 'is_active' => true]);
        $this->actingAs($resident)->postJson('/api/requests', ['document_id' => $document->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['purpose', 'additional_information.note']);
        $this->assertDatabaseCount('document_requests', 0);
    }
    public function test_unread_notification_filter_keeps_dates_and_only_returns_owned_unread_items(): void
    {
        $resident = User::factory()->create(['account_status' => 'active']);
        $other = User::factory()->create(['account_status' => 'active']);
        $unread = ResidentNotification::create(['user_id' => $resident->id, 'title' => 'Pending review', 'message' => 'Your request is being reviewed.']);
        ResidentNotification::create(['user_id' => $resident->id, 'title' => 'Old update', 'message' => 'Read already', 'read_at' => now()]);
        ResidentNotification::create(['user_id' => $other->id, 'title' => 'Private update', 'message' => 'Other user']);
        $this->actingAs($resident)->getJson('/api/notifications')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/notifications/unread-count')->assertOk()->assertExactJson(['count' => 1]);
        $this->getJson('/api/notifications?unread=1')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $unread->id)->assertJsonStructure(['data' => [['created_at']]]);
        $this->patchJson('/api/notifications/'.$unread->id.'/read')->assertOk();
        $this->getJson('/api/notifications?unread=1')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/notifications/unread-count')->assertOk()->assertExactJson(['count' => 0]);
        $this->assertNull($other->residentNotifications()->first()->read_at);
    }
}
