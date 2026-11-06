<?php
namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

class WordTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function wordFile(string $text): UploadedFile
    {
        $word = new PhpWord;
        $word->addSection()->addText($text);
        $path = tempnam(sys_get_temp_dir(), 'template');
        try {
            IOFactory::createWriter($word, 'Word2007')->save($path);
            return UploadedFile::fake()->createWithContent('clearance.docx', file_get_contents($path));
        } finally { unlink($path); }
    }

    public function test_word_template_is_published_and_the_same_version_is_used_in_editor(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'account_status' => 'active']);
        $resident = User::factory()->create(['role' => 'resident', 'account_status' => 'active']);
        $document = Document::create(['name' => 'Clearance', 'slug' => 'clearance', 'fee' => 0, 'is_active' => true]);
        $upload = $this->actingAs($admin)->post("/api/admin/documents/{$document->id}/template", ['template' => $this->wordFile('Original template for {{resident_name}}')]);
        $upload->assertCreated();
        Storage::assertExists($upload->json('template.uploaded_path'));
        $this->actingAs($resident)->getJson('/api/documents')->assertOk()->assertJsonPath('0.templates_exists', true);
        $this->get("/api/documents/{$document->id}/template")->assertOk()->assertSee('Original template', false);
        $this->get("/api/documents/{$document->id}/template?download=1")->assertOk()->assertDownload('clearance.docx');
        $this->postJson('/api/requests', ['document_id' => $document->id, 'purpose' => 'Employment', 'additional_information' => ['note' => 'Local job']])->assertCreated();
        $record = DocumentRequest::firstOrFail();
        $this->assertEquals($upload->json('template.id'), $record->document_snapshot['template_id']);
        $record->update(['status' => 'Approved']);
        $this->actingAs($admin)->post("/api/admin/documents/{$document->id}/template", ['template' => $this->wordFile('Replacement template')])->assertCreated();
        $draft = $this->getJson("/api/admin/requests/{$record->id}/document-draft")->assertOk();
        $this->assertStringContainsString('Original template', $draft->json('content'));
        $this->assertStringContainsString($resident->name, $draft->json('content'));
        $this->assertStringNotContainsString('Replacement template', $draft->json('content'));
        $this->putJson("/api/admin/requests/{$record->id}/document-draft", ['content' => '<p>Edited uploaded template</p>'])->assertOk();
        $this->getJson("/api/admin/requests/{$record->id}/document-draft")->assertJsonPath('content', '<p>Edited uploaded template</p>');
        $this->actingAs($resident)->get("/api/documents/{$document->id}/template")->assertSee('Replacement template', false);
        $this->actingAs($admin)->getJson("/api/admin/documents/{$document->id}/template-draft")->assertOk();
        $this->putJson("/api/admin/documents/{$document->id}/template-draft", ['content' => '<p>Edited catalog template</p><script>alert(1)</script>'])->assertOk();
        $this->getJson("/api/admin/documents/{$document->id}/template-draft")->assertJsonPath('content', '<p>Edited catalog template</p>');
        $this->getJson("/api/admin/requests/{$record->id}/document-draft")->assertJsonPath('content', '<p>Edited uploaded template</p>');
        $this->actingAs($resident)->get("/api/documents/{$document->id}/template")->assertSee('Edited catalog template', false);
        $this->putJson("/api/admin/documents/{$document->id}/template-draft", ['content' => '<p>Unauthorized edit</p>'])->assertForbidden();
    }

    public function test_resident_cannot_upload_and_invalid_or_inactive_templates_are_blocked(): void
    {
        Storage::fake('local');
        $resident = User::factory()->create(['role' => 'resident', 'account_status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'account_status' => 'active']);
        $document = Document::create(['name' => 'Clearance', 'slug' => 'clearance', 'fee' => 0, 'is_active' => false]);
        $this->actingAs($resident)->postJson("/api/admin/documents/{$document->id}/template", ['template' => $this->wordFile('Template')])->assertForbidden();
        $this->get("/api/documents/{$document->id}/template")->assertNotFound();
        $this->actingAs($admin)->postJson("/api/admin/documents/{$document->id}/template", ['template' => UploadedFile::fake()->createWithContent('broken.docx', 'not a Word file')])->assertUnprocessable();
        $this->assertDatabaseCount('document_templates', 0);
    }
}