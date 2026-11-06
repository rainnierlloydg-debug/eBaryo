<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\DocumentTemplate;
use App\Models\CashPayment;
use App\Models\User;
use App\Services\RequestWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_unpaid_request_can_be_released_after_pickup_ready(): void
    {
        $resident = User::factory()->create(['role' => 'resident', 'verification_status' => 'verified']);
        $admin = User::factory()->create(['role' => 'admin', 'verification_status' => 'verified']);
        $document = Document::create([
            'name' => 'Barangay Clearance',
            'slug' => 'barangay-clearance',
            'fee' => 100,
            'requirements' => ['Valid ID'],
            'is_active' => true,
        ]);
        $record = DocumentRequest::create([
            'tracking_number' => 'BRGY-2026-000001',
            'user_id' => $resident->id,
            'document_id' => $document->id,
            'document_snapshot' => ['name' => $document->name],
            'resident_snapshot' => ['name' => $resident->name, 'address' => 'Poblacion'],
            'purpose' => 'Employment',
            'status' => 'Submitted',
            'fee' => 100,
            'payment_status' => 'Unpaid',
            'submitted_at' => now(),
        ]);
        $workflow = app(RequestWorkflow::class);

        foreach (['Under Review', 'Approved', 'Processing', 'Ready for Pickup', 'Released'] as $status) {
            $record = $workflow->transition($record, $admin, $status);
        }

        $this->assertSame('Released', $record->status);
        $this->assertSame('Unpaid', $record->payment_status);
        $this->assertNotNull($record->released_at);
        $this->assertNotNull($record->pickup_deadline);
        $this->assertDatabaseCount('request_timelines', 5);
    }

    public function test_resident_can_submit_a_request_without_requirement_uploads(): void
    {
        $resident = User::factory()->create([
            'role' => 'resident',
            'account_status' => 'active',
            'verification_status' => 'verified',
        ]);
        $document = Document::create([
            'name' => 'Barangay Clearance',
            'slug' => 'barangay-clearance-no-upload',
            'fee' => 100,
            'requirements' => ['Valid ID'],
            'is_active' => true,
        ]);

        $this->actingAs($resident)->postJson('/api/requests', [
            'document_id' => $document->id,
            'purpose' => 'Employment',
            'additional_information' => ['note' => 'For an employment application.'],
        ])->assertCreated()
            ->assertJsonPath('status', 'Submitted');

        $this->assertDatabaseCount('requirement_uploads', 0);
    }

    public function test_rejection_requires_a_reason(): void
    {
        $resident = User::factory()->create(['role' => 'resident', 'account_status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'account_status' => 'active']);
        $document = Document::create(['name' => 'Certificate', 'slug' => 'certificate', 'fee' => 0, 'requirements' => []]);
        $record = DocumentRequest::create([
            'tracking_number' => 'BRGY-2026-000002',
            'user_id' => $resident->id,
            'document_id' => $document->id,
            'document_snapshot' => ['name' => $document->name],
            'resident_snapshot' => ['name' => $resident->name, 'address' => 'Poblacion'],
            'purpose' => 'School',
            'status' => 'Under Review',
            'fee' => 0,
            'payment_status' => 'Unpaid',
            'submitted_at' => now(),
        ]);

        try {
            app(RequestWorkflow::class)->transition($record, $admin, 'Rejected');
            $this->fail('A rejection without a reason should be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        $this->assertSame('Under Review', $record->fresh()->status);
    }

    public function test_admin_can_mark_request_under_review_with_empty_correction_fields(): void
    {
        $resident = User::factory()->create(['role' => 'resident', 'account_status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'account_status' => 'active']);
        $document = Document::create(['name' => 'Certificate', 'slug' => 'under-review', 'fee' => 0, 'requirements' => []]);
        $record = DocumentRequest::create([
            'tracking_number' => 'BRGY-2026-000008',
            'user_id' => $resident->id,
            'document_id' => $document->id,
            'document_snapshot' => ['name' => $document->name],
            'resident_snapshot' => ['name' => $resident->name, 'address' => 'Poblacion'],
            'purpose' => 'Employment',
            'status' => 'Submitted',
            'fee' => 0,
            'payment_status' => 'Unpaid',
            'submitted_at' => now(),
        ]);

        $this->actingAs($admin)->patchJson("/api/admin/requests/{$record->id}/status", [
            'status' => 'Under Review',
            'reason' => '',
            'instruction' => '',
            'fields' => [],
        ])->assertOk()->assertJsonPath('status', 'Under Review');
    }

    public function test_admin_can_save_status_and_payment_together_atomically(): void
    {
        $resident = User::factory()->create(['role' => 'resident', 'account_status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'account_status' => 'active']);
        $document = Document::create(['name' => 'Certificate', 'slug' => 'combined-update', 'fee' => 50, 'requirements' => []]);
        $record = DocumentRequest::create([
            'tracking_number' => 'BRGY-2026-000010',
            'user_id' => $resident->id,
            'document_id' => $document->id,
            'document_snapshot' => ['name' => $document->name],
            'resident_snapshot' => ['name' => $resident->name, 'address' => 'Poblacion'],
            'purpose' => 'Employment',
            'status' => 'Processing',
            'fee' => 50,
            'payment_status' => 'Unpaid',
            'submitted_at' => now(),
        ]);

        $this->actingAs($admin)->patchJson("/api/admin/requests/{$record->id}/updates", [
            'status' => 'Released',
            'amount_paid' => 50,
            'paid_at' => now()->toDateTimeString(),
        ])->assertUnprocessable();
        $this->assertSame('Processing', $record->fresh()->status);
        $this->assertSame('Unpaid', $record->fresh()->payment_status);
        $this->assertDatabaseCount('cash_payments', 0);

        $this->patchJson("/api/admin/requests/{$record->id}/updates", [
            'status' => 'Ready for Pickup',
            'amount_paid' => 50,
            'paid_at' => now()->toDateTimeString(),
        ])->assertOk()
            ->assertJsonPath('status', 'Ready for Pickup')
            ->assertJsonPath('payment_status', 'Paid');

        $this->assertSame('Ready for Pickup', $record->fresh()->status);
        $this->assertSame('Paid', $record->fresh()->payment_status);
        $this->assertDatabaseCount('cash_payments', 1);
        $this->assertDatabaseCount('request_timelines', 1);
        $this->assertDatabaseHas('resident_notifications', [
            'user_id' => $resident->id,
            'title' => 'Request Ready for Pickup',
        ]);
    }

    public function test_resident_history_shows_payment_status_without_payment_details(): void
    {
        $resident = User::factory()->create(['role' => 'resident', 'account_status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin']);
        $document = Document::create(['name' => 'Certificate', 'slug' => 'certificate', 'fee' => 20, 'requirements' => []]);
        $record = DocumentRequest::create([
            'tracking_number' => 'BRGY-2026-000003',
            'user_id' => $resident->id,
            'document_id' => $document->id,
            'document_snapshot' => ['name' => $document->name],
            'resident_snapshot' => ['name' => $resident->name, 'address' => 'Poblacion'],
            'purpose' => 'School',
            'status' => 'Submitted',
            'fee' => 20,
            'payment_status' => 'Paid',
            'submitted_at' => now(),
        ]);
        CashPayment::create([
            'document_request_id' => $record->id,
            'recorded_by' => $admin->id,
            'amount_paid' => 20,
            'paid_at' => now(),
        ]);

        $this->actingAs($resident)->getJson('/api/requests')
            ->assertOk()
            ->assertJsonPath('data.0.payment_status', 'Paid')
            ->assertJsonMissingPath('data.0.payments');
    }

    public function test_resident_can_cancel_only_while_under_review(): void
    {
        $resident = User::factory()->create(['role' => 'resident', 'account_status' => 'active']);
        $document = Document::create(['name' => 'Certificate', 'slug' => 'certificate', 'fee' => 0, 'requirements' => []]);
        $record = DocumentRequest::create([
            'tracking_number' => 'BRGY-2026-000004',
            'user_id' => $resident->id,
            'document_id' => $document->id,
            'document_snapshot' => ['name' => $document->name],
            'resident_snapshot' => ['name' => $resident->name, 'address' => 'Poblacion'],
            'purpose' => 'School',
            'status' => 'Submitted',
            'fee' => 0,
            'payment_status' => 'Unpaid',
            'submitted_at' => now(),
        ]);

        $this->actingAs($resident)->patchJson("/api/requests/{$record->id}/cancel", ['reason_type' => 'No longer needed'])
            ->assertUnprocessable();
        $record->update(['status' => 'Under Review']);
        $this->patchJson("/api/requests/{$record->id}/cancel", ['reason_type' => 'No longer needed'])
            ->assertOk()
            ->assertJsonPath('status', 'Cancelled');
    }

    public function test_resident_cannot_change_fields_not_requested_for_correction(): void
    {
        $resident = User::factory()->create(['role' => 'resident', 'account_status' => 'active']);
        $document = Document::create(['name' => 'Certificate', 'slug' => 'certificate', 'fee' => 0, 'requirements' => ['Valid ID']]);
        $record = DocumentRequest::create([
            'tracking_number' => 'BRGY-2026-000005',
            'user_id' => $resident->id,
            'document_id' => $document->id,
            'document_snapshot' => ['name' => $document->name, 'requirements' => ['Valid ID']],
            'resident_snapshot' => ['name' => $resident->name, 'address' => 'Poblacion'],
            'purpose' => 'School',
            'status' => 'For Correction',
            'fee' => 0,
            'payment_status' => 'Unpaid',
            'correction_fields' => ['Valid ID'],
            'correction_instruction' => 'Upload a clearer image.',
            'submitted_at' => now(),
        ]);

        $this->actingAs($resident)->postJson("/api/requests/{$record->id}/resubmit", ['purpose' => 'Unauthorized change'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('purpose');
        $this->assertSame('For Correction', $record->fresh()->status);
    }

    public function test_admin_can_generate_and_open_a_printable_document(): void
    {
        Storage::fake('local');
        $resident = User::factory()->create(['role' => 'resident', 'account_status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'account_status' => 'active']);
        $document = Document::create(['name' => 'Barangay Clearance', 'slug' => 'clearance', 'fee' => 50, 'requirements' => []]);
        $record = DocumentRequest::create([
            'tracking_number' => 'BRGY-2026-000006',
            'user_id' => $resident->id,
            'document_id' => $document->id,
            'document_snapshot' => ['name' => $document->name],
            'resident_snapshot' => ['name' => 'Juan Dela Cruz', 'address' => 'Poblacion'],
            'purpose' => 'Employment',
            'status' => 'Approved',
            'fee' => 50,
            'payment_status' => 'Unpaid',
            'submitted_at' => now(),
        ]);

        $generation = $this->actingAs($admin)->postJson("/api/admin/requests/{$record->id}/generate")
            ->assertOk();
        $this->assertSame(
            "/api/admin/requests/{$record->id}/generated-document?v=".$record->fresh()->updated_at->timestamp,
            $generation->json('preview_url'),
        );
        $printResponse = $this->get("/api/admin/requests/{$record->id}/generated-document")->assertOk();
        $this->assertStringContainsString('no-store', $printResponse->headers->get('Cache-Control'));
        $printResponse
            ->assertSee('Juan Dela Cruz')
            ->assertSee('BRGY-2026-000006');
    }

    public function test_admin_can_save_a_document_draft_and_print_the_saved_content(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'account_status' => 'active']);
        $document = Document::create([
            'name' => 'Barangay Clearance',
            'slug' => 'clearance-draft',
            'fee' => 50,
            'requirements' => [],
        ]);
        DocumentTemplate::create([
            'document_id' => $document->id,
            'name' => 'Clearance print layout',
            'body' => '<!doctype html><html><head><style>@page{size:8.5in 11in;margin:0}.certificate{width:8.5in;height:11in;color:#123456}</style></head><body><main class="certificate"><h1>{{document_name}}</h1><p>{{resident_name}}</p></main></body></html>',
        ]);
        $record = DocumentRequest::create([
            'tracking_number' => 'BRGY-2026-000007',
            'user_id' => User::factory()->create(['role' => 'resident'])->id,
            'document_id' => $document->id,
            'document_snapshot' => ['name' => $document->name],
            'resident_snapshot' => ['name' => 'Juan Dela Cruz', 'address' => 'Poblacion'],
            'purpose' => 'Employment',
            'status' => 'Approved',
            'fee' => 50,
            'payment_status' => 'Unpaid',
            'submitted_at' => now(),
        ]);

        $draftResponse = $this->actingAs($admin)->getJson("/api/admin/requests/{$record->id}/document-draft")
            ->assertOk()
            ->assertJsonPath('saved_at', null);
        $this->assertStringContainsString('Juan Dela Cruz', $draftResponse->json('content'));
        $this->assertStringContainsString('@page{size:8.5in 11in;margin:0}', $draftResponse->json('content'));
        $this->assertStringContainsString('class="certificate"', $draftResponse->json('content'));
        $this->putJson("/api/admin/requests/{$record->id}/document-draft", [
            'content' => '<style>@page{size:8.5in 11in;margin:0}.certificate{width:8.5in;height:11in;color:#123456}</style><main class="certificate"><h1 style="text-align: center" onclick="alert(1)">Edited Clearance</h1><p><font face="Arial" size="5" color="#123456">Font formatting</font> <a href="https://example.test">More info</a></p><p>Saved for later.</p><script>alert(2)</script></main>',
        ])->assertOk();

        $savedDraft = $record->fresh()->document_draft;
        $this->assertStringContainsString('Edited Clearance', strip_tags($savedDraft));
        $this->assertStringContainsString('@page{size:8.5in 11in;margin:0}', $savedDraft);
        $this->assertStringContainsString('class="certificate"', $savedDraft);
        $this->assertStringContainsString('<font face="Arial" size="5" color="#123456">Font formatting</font>', $savedDraft);
        $this->assertStringContainsString('<a href="https://example.test">More info</a>', $savedDraft);
        $this->assertNotNull($record->fresh()->document_draft_saved_at);
        $this->postJson("/api/admin/requests/{$record->id}/generate")->assertOk();
        $this->get("/api/admin/requests/{$record->id}/generated-document")
            ->assertOk()
            ->assertSee('Edited Clearance')
            ->assertSee('@page{size:8.5in 11in;margin:0}')
            ->assertSeeHtml('class="certificate"')
            ->assertDontSee('onclick')
            ->assertDontSee('alert(2)');
    }

    public function test_template_management_is_not_available_in_the_admin_flow(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'account_status' => 'active']);
        $document = Document::create(['name' => 'Clearance', 'slug' => 'no-template-management', 'fee' => 0, 'requirements' => []]);
        $this->actingAs($admin)->postJson('/api/admin/documents/'.$document->id.'/templates', [
            'name' => 'Extra template',
        ])->assertNotFound();
        $this->patchJson('/api/admin/templates/1', ['name' => 'Changed'])->assertNotFound();
        $this->deleteJson('/api/admin/templates/1')->assertNotFound();
        $this->assertDatabaseCount('document_templates', 0);
    }

    public function test_registration_waits_for_admin_review_before_login(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'account_status' => 'active']);
        $image = file_get_contents(base_path('docs/flowcharts/02b_Registration_AI_Verification.png'));
        $this->post('/api/register', [
            'first_name' => 'Test',
            'last_name' => 'Resident',
            'phone' => '09123456789',
            'barangay' => 'Poblacion',
            'sitio' => 'Centro',
            'email' => 'pending@example.test',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'national_id_front' => UploadedFile::fake()->createWithContent('front.png', $image),
            'national_id_back' => UploadedFile::fake()->createWithContent('back.png', $image),
        ])->assertAccepted()
            ->assertJsonPath('status', 'pending');

        $this->assertGuest();
        $resident = User::where('email', 'pending@example.test')->firstOrFail();
        $this->assertSame('pending', $resident->account_status);
        $this->assertSame('pending', $resident->verification_status);
        Storage::disk('local')->assertExists($resident->national_id_path);
        Storage::disk('local')->assertExists($resident->national_id_back_path);
        $this->assertSame('Test Resident', $resident->name);
        $this->assertSame('Centro, Poblacion', $resident->address);
        $this->assertSame('09123456789', $resident->phone);
        $this->assertSame('Test', $resident->first_name);
        $this->assertSame('Resident', $resident->last_name);
        $this->assertSame('Poblacion', $resident->barangay);
        $this->assertSame('Centro', $resident->sitio);
        $this->assertDatabaseHas('resident_notifications', [
            'user_id' => $admin->id,
            'title' => 'Registration awaiting review',
        ]);

        $this->postJson('/api/login', ['email' => $resident->email, 'password' => 'SecurePass123!'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->actingAs($admin)->get("/api/admin/residents/{$resident->id}/national-id")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        $this->get("/api/admin/residents/{$resident->id}/national-id?side=back")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        $this->postJson("/api/admin/residents/{$resident->id}/approve-registration")
            ->assertOk();

        $this->postJson('/api/login', ['email' => $resident->email, 'password' => 'SecurePass123!'])
            ->assertOk()
            ->assertJsonPath('redirect', '/dashboard');
    }

    public function test_admin_rejection_requires_reason_and_deletes_registration(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'account_status' => 'active']);
        $resident = User::factory()->create([
            'role' => 'resident',
            'account_status' => 'pending',
            'verification_status' => 'pending',
        ]);

        $this->actingAs($admin)->postJson("/api/admin/residents/{$resident->id}/reject-registration")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
        $this->postJson("/api/admin/residents/{$resident->id}/reject-registration", ['reason' => 'ID details do not match.'])
            ->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $resident->id]);
        $this->postJson('/api/login', ['email' => $resident->email, 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }
}
