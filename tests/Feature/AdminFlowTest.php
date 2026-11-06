<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\ResidentNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminFlowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'account_status' => 'active', 'verification_status' => 'verified']);
    }

    private function resident(): User
    {
        return User::factory()->create(['role' => 'resident', 'account_status' => 'active', 'verification_status' => 'verified']);
    }

    private function requestRecord(User $resident, string $status = 'Submitted'): DocumentRequest
    {
        return DocumentRequest::create([
            'tracking_number' => 'FLOW-'.fake()->unique()->numerify('######'),
            'user_id' => $resident->id,
            'document_snapshot' => ['name' => 'Clearance'],
            'resident_snapshot' => ['name' => $resident->name],
            'purpose' => 'Employment', 'status' => $status,
            'submitted_at' => now(), 'fee' => 0, 'payment_status' => 'Unpaid',
        ]);
    }

    public function test_request_search_and_archive_view_follow_the_diagram(): void
    {
        $admin = $this->admin();
        $resident = $this->resident();
        $record = $this->requestRecord($resident, 'Released');
        $archived = $this->requestRecord($resident, 'Rejected');
        $archived->update(['archived_at' => now()]);
        $this->actingAs($admin)->getJson('/api/admin/requests?search='.$resident->id.'&status=Released&payment_status=Unpaid&date='.now()->toDateString())
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $record->id);
        $this->getJson('/api/admin/requests?archived=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $archived->id);
        $this->patchJson('/api/admin/requests/'.$record->id.'/archive', ['archived' => true])->assertNotFound();
        $this->assertNull($record->fresh()->archived_at);
    }

    public function test_unlisted_resident_management_and_system_settings_operations_are_unavailable(): void
    {
        $resident = $this->resident();
        $this->actingAs($this->admin())->patchJson('/api/admin/residents/'.$resident->id, ['email' => 'changed@example.test'])->assertNotFound();
        $this->deleteJson('/api/admin/residents/'.$resident->id)->assertNotFound();
        $this->postJson('/api/admin/residents/'.$resident->id.'/reset-password')->assertNotFound();
        $this->patchJson('/api/admin/settings', ['settings' => ['barangay_name' => 'Other']])->assertNotFound();
        $this->patchJson('/api/profile', ['name' => 'Changed Admin'])->assertForbidden();
        $this->assertModelExists($resident);
        $this->assertSame($resident->email, $resident->fresh()->email);
    }

    public function test_opening_one_report_and_marking_all_reports_read_persist(): void
    {
        $first = $this->requestRecord($this->resident());
        $second = $this->requestRecord($this->resident());
        $this->actingAs($this->admin())->getJson('/api/admin/reports/'.$first->id)->assertOk();
        $this->assertNotNull($first->fresh()->admin_read_at);
        $this->assertNull($second->fresh()->admin_read_at);
        $this->patchJson('/api/admin/reports/read-all')->assertOk();
        $this->assertNotNull($second->fresh()->admin_read_at);
    }

    public function test_notifications_require_valid_resident_recipients_and_single_means_one(): void
    {
        $admin = $this->admin();
        $first = $this->resident();
        $second = $this->resident();
        $payload = ['title' => 'Notice', 'message' => 'Bring your ID.', 'priority' => 'normal', 'recipient_type' => 'selected'];
        $this->actingAs($admin)->postJson('/api/admin/notifications', $payload + ['resident_ids' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('resident_ids');
        $this->postJson('/api/admin/notifications', $payload + ['resident_ids' => [$admin->id]])->assertUnprocessable();
        $this->postJson('/api/admin/notifications', array_merge($payload, ['recipient_type' => 'individual', 'resident_ids' => [$first->id, $second->id]]))->assertUnprocessable();
        $this->assertDatabaseCount('resident_notifications', 0);
        $this->postJson('/api/admin/notifications', $payload + ['resident_ids' => [$first->id, $second->id]])->assertCreated()->assertJsonPath('sent_to', 2);
        $this->assertDatabaseCount('resident_notifications', 2);
    }

    public function test_notification_attachments_are_downloadable_only_by_recipient_or_admin(): void
    {
        Storage::fake();
        $admin = $this->admin();
        $resident = $this->resident();
        $other = $this->resident();
        $this->actingAs($admin)->post('/api/admin/notifications', [
            'title' => 'Pickup instructions', 'message' => 'Read the attached instructions.',
            'priority' => 'important', 'recipient_type' => 'individual', 'resident_ids' => [$resident->id],
            'documents' => [UploadedFile::fake()->create('instructions.pdf', 10, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('sent_to', 1);
        $notification = ResidentNotification::sole();
        Storage::assertExists($notification->attachments[0]['path']);
        $url = '/api/notifications/'.$notification->id.'/documents/0';
        $this->actingAs($resident)->getJson('/api/notifications')->assertJsonPath('data.0.documents.0.url', $url)->assertJsonMissingPath('data.0.attachments');
        $this->get($url)->assertDownload('instructions.pdf');
        $this->actingAs($other)->get($url)->assertNotFound();
        $this->actingAs($admin)->get($url)->assertDownload('instructions.pdf');
    }

    public function test_all_residents_notification_does_not_require_ids_or_documents(): void
    {
        $this->resident();
        $this->resident();
        $this->actingAs($this->admin())->postJson('/api/admin/notifications', [
            'title' => 'Office hours', 'message' => 'Open at 8 AM.', 'priority' => 'normal', 'recipient_type' => 'all',
        ])->assertCreated()->assertJsonPath('sent_to', 2);
    }

    public function test_document_fields_are_required_but_activation_can_be_updated_independently(): void
    {
        $this->actingAs($this->admin())->postJson('/api/admin/documents', ['name' => 'Certificate', 'fee' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors(['description', 'purpose', 'requirements']);
        $response = $this->postJson('/api/admin/documents', [
            'name' => 'Certificate', 'description' => 'Barangay certificate', 'purpose' => 'Employment',
            'fee' => 0, 'requirements' => ['Application form'],
        ])->assertCreated();
        $id = $response->json('id');
        $this->patchJson('/api/admin/documents/'.$id, ['is_active' => false])->assertOk()->assertJsonPath('is_active', false);
        $this->patchJson('/api/admin/documents/'.$id, ['purpose' => ''])->assertUnprocessable();
        $this->patchJson('/api/admin/documents/'.$id, ['is_active' => true])->assertUnprocessable();
        $this->assertSame('Employment', Document::findOrFail($id)->purpose);
    }

    public function test_admin_preferences_are_saved_per_account(): void
    {
        $admin = $this->admin();
        $other = $this->admin();
        $this->actingAs($admin)->patchJson('/api/notification-preferences', ['preferences' => ['email' => false, 'in_app' => true]])->assertOk();
        $this->getJson('/api/profile')->assertJsonPath('notification_preferences.email', false)->assertJsonPath('notification_preferences.in_app', true);
        $this->assertNull($other->fresh()->notification_preferences);
    }

    public function test_dashboard_and_reports_only_expose_the_diagram_content(): void
    {
        $this->requestRecord($this->resident());
        $this->actingAs($this->admin())->getJson('/api/admin/dashboard')
            ->assertOk()->assertExactJson(['total_requests' => 1, 'pending_registrations' => 0]);
        $this->getJson('/api/admin/reports')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonMissingPath('per_document')->assertJsonMissingPath('approved');
    }
    public function test_admin_sidebar_counts_track_unread_requests_and_own_notifications(): void
    {
        $admin = $this->admin();
        $resident = $this->resident();
        $record = $this->requestRecord($resident);
        $archived = $this->requestRecord($resident);
        $archived->update(['archived_at' => now()]);
        $read = $this->requestRecord($resident);
        $read->update(['admin_read_at' => now()]);
        ResidentNotification::create(['user_id' => $admin->id, 'title' => 'New registration', 'message' => 'Review registration']);
        ResidentNotification::create(['user_id' => $resident->id, 'title' => 'Private', 'message' => 'Resident only']);
        $this->actingAs($resident)->getJson('/api/admin/navigation-counts')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/admin/navigation-counts')->assertOk()->assertExactJson(['requests' => 1, 'notifications' => 1]);
        $this->getJson('/api/admin/requests/'.$record->id)->assertOk();
        $this->getJson('/api/admin/navigation-counts')->assertExactJson(['requests' => 0, 'notifications' => 1]);
        $this->patchJson('/api/notifications/read-all')->assertOk();
        $this->getJson('/api/admin/navigation-counts')->assertExactJson(['requests' => 0, 'notifications' => 0]);
        $this->assertNull($resident->residentNotifications()->first()->read_at);
    }
}
