<?php

namespace App\Http\Controllers;

use App\Models\CashPayment;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\DocumentTemplate;
use App\Models\ResidentNotification;
use App\Models\User;
use App\Services\RequestWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function dashboard()
    {
        return response()->json([
            'total_requests' => DocumentRequest::count(),
            'pending_registrations' => User::where('role', 'resident')->where('verification_status', 'pending')->count(),
        ]);
    }

    public function navigationCounts(Request $request)
    {
        return response()->json([
            'requests' => DocumentRequest::whereNull('archived_at')->whereNull('admin_read_at')->count(),
            'notifications' => $request->user()->residentNotifications()->whereNull('read_at')->count(),
        ]);
    }
    public function requests(Request $request)
    {
        $records = DocumentRequest::query()->with(['resident:id,name,email', 'document:id,name'])
            ->when($request->boolean('archived'), fn ($query) => $query->whereNotNull('archived_at'), fn ($query) => $query->whereNull('archived_at'))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = $request->string('search')->toString();
                $query->where(fn ($builder) => $builder->where('user_id', ctype_digit($term) ? (int) $term : 0)
                    ->orWhereHas('resident', fn ($resident) => $resident->where('name', 'like', "%{$term}%")));
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('document_id'), fn ($query) => $query->where('document_id', $request->integer('document_id')))
            ->when($request->filled('payment_status'), fn ($query) => $query->where('payment_status', $request->string('payment_status')->toString()))
            ->when($request->filled('date'), fn ($query) => $query->whereDate('submitted_at', $request->date('date')))
            ->latest('submitted_at')->paginate(25);

        return response()->json($records);
    }

    public function showRequest(DocumentRequest $documentRequest)
    {
        $documentRequest->update(['admin_read_at' => now()]);
        return response()->json($documentRequest->load([
            'resident:id,name,address,email,phone,verification_status,account_status',
            'document', 'uploads', 'timeline.actor:id,name,role', 'payments',
        ]));
    }

    public function markReportsRead()
    {
        DocumentRequest::whereNull('archived_at')->whereNull('admin_read_at')->update(['admin_read_at' => now()]);

        return response()->json(['message' => 'All request reports marked as read.']);
    }

    public function updateStatus(Request $request, DocumentRequest $documentRequest, RequestWorkflow $workflow)
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:Under Review,Approved,Rejected,For Correction,Processing,Ready for Pickup,Released,Cancelled'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'fields' => $request->input('status') === 'For Correction'
                ? ['required', 'array', 'min:1']
                : ['sometimes', 'nullable', 'array'],
            'fields.*' => ['string', 'max:150'],
            'instruction' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json($workflow->transition($documentRequest, $request->user(), $data['status'], $data));
    }

    public function updateRequest(Request $request, DocumentRequest $documentRequest, RequestWorkflow $workflow)
    {
        $data = $request->validate([
            'status' => ['required_without:amount_paid', 'nullable', 'string', 'in:Under Review,Approved,Rejected,For Correction,Processing,Ready for Pickup,Released,Cancelled'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'fields' => $request->input('status') === 'For Correction'
                ? ['required', 'array', 'min:1']
                : ['sometimes', 'nullable', 'array'],
            'fields.*' => ['string', 'max:150'],
            'instruction' => ['nullable', 'string', 'max:2000'],
            'amount_paid' => ['sometimes', 'nullable', 'numeric', 'min:0.01', 'max:99999999'],
            'paid_at' => ['required_with:amount_paid', 'nullable', 'date'],
        ]);

        $updated = DB::transaction(function () use ($request, $documentRequest, $workflow, $data): DocumentRequest {
            if (filled($data['status'] ?? null)) {
                $workflow->transition($documentRequest, $request->user(), $data['status'], $data);
            }

            if (filled($data['amount_paid'] ?? null)) {
                CashPayment::create([
                    'document_request_id' => $documentRequest->id,
                    'recorded_by' => $request->user()->id,
                    'amount_paid' => $data['amount_paid'],
                    'paid_at' => $data['paid_at'],
                ]);
                $documentRequest->update(['payment_status' => 'Paid']);
            }

            return $documentRequest->fresh();
        });

        return response()->json([
            'status' => $updated->status,
            'payment_status' => $updated->payment_status,
        ]);
    }

    public function documentDraft(DocumentRequest $documentRequest)
    {
        abort_unless($documentRequest->status === 'Approved', 422, 'Only approved requests can be edited.');
        $template = $this->requestTemplate($documentRequest);
        $isWordTemplate = $template?->uploaded_path
            && strtolower(pathinfo($template->uploaded_path, PATHINFO_EXTENSION)) === 'docx';

        return response()->json([
            'content' => $documentRequest->document_draft ?? $this->initialDocumentDraft($documentRequest),
            'saved_at' => $documentRequest->document_draft_saved_at?->toIso8601String(),
            'word_template_url' => $isWordTemplate ? "/api/admin/requests/{$documentRequest->id}/word-template" : null,
        ]);
    }

    public function documentWordTemplate(DocumentRequest $documentRequest)
    {
        abort_unless($documentRequest->status === 'Approved', 422, 'Only approved requests can be edited.');
        $template = $this->requestTemplate($documentRequest);
        abort_unless($template?->uploaded_path
            && strtolower(pathinfo($template->uploaded_path, PATHINFO_EXTENSION)) === 'docx', 404);

        return response(Storage::get($template->uploaded_path))
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
            ->header('Cache-Control', 'private, no-store');
    }

    public function saveDocumentDraft(Request $request, DocumentRequest $documentRequest)
    {
        abort_unless($documentRequest->status === 'Approved', 422, 'Only approved requests can be edited.');
        $data = $request->validate([
            'content' => ['required', 'string', 'max:500000'],
        ]);

        $documentRequest->update([
            'document_draft' => $this->sanitizeDocumentHtml($data['content']),
            'document_draft_saved_at' => now(),
        ]);

        return response()->json([
            'saved_at' => $documentRequest->document_draft_saved_at?->toIso8601String(),
        ]);
    }

    public function recordPayment(Request $request, DocumentRequest $documentRequest)
    {
        $data = $request->validate([
            'amount_paid' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'paid_at' => ['required', 'date'],
        ]);

        DB::transaction(function () use ($request, $documentRequest, $data): void {
            CashPayment::create([
                'document_request_id' => $documentRequest->id,
                'recorded_by' => $request->user()->id,
                'amount_paid' => $data['amount_paid'],
                'paid_at' => $data['paid_at'],
            ]);
            $documentRequest->update(['payment_status' => 'Paid']);
        });

        return response()->json(['payment_status' => 'Paid']);
    }

    public function generateDocument(DocumentRequest $documentRequest)
    {
        abort_unless($documentRequest->status === 'Approved', 422, 'Only approved requests can be generated.');
        $body = $documentRequest->document_draft ?? $this->initialDocumentDraft($documentRequest);
        $html = $this->printableDocumentHtml($body, $documentRequest->tracking_number);
        $path = 'generated-documents/'.$documentRequest->tracking_number.'.html';
        Storage::put($path, $html);
        $documentRequest->update(['generated_document_path' => $path]);
        $version = $documentRequest->fresh()->updated_at?->timestamp ?? now()->timestamp;

        return response()->json(['path' => $path, 'preview_url' => "/api/admin/requests/{$documentRequest->id}/generated-document?v={$version}"]);
    }

    private function initialDocumentDraft(DocumentRequest $documentRequest): string
    {
        $template = $this->requestTemplate($documentRequest);
        $body = $template?->body ?: '<h1>{{document_name}}</h1><p>Document No. {{document_number}}</p><p>This certifies that {{resident_name}}, residing at {{address}}, requested this document for {{purpose}}.</p><p>Issued this {{issue_date}}.</p><p><br><br>____________________________<br>Barangay Official</p>';
        $values = [
            '{{resident_name}}' => e($documentRequest->resident_snapshot['name'] ?? ''),
            '{{address}}' => e($documentRequest->resident_snapshot['address'] ?? ''),
            '{{document_number}}' => e($documentRequest->tracking_number),
            '{{document_name}}' => e($documentRequest->document_snapshot['name'] ?? ''),
            '{{purpose}}' => e($documentRequest->purpose),
            '{{issue_date}}' => now()->format('F j, Y'),
            '{{email}}' => e($documentRequest->resident_snapshot['email'] ?? ''),
        ];

        return strtr($body, $values);
    }

    private function sanitizeDocumentHtml(string $html): string
    {
        $html = preg_replace('#<(script|iframe|object|embed|applet|svg)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? '';
        $html = preg_replace('/\s+on[a-z0-9_-]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s+(href|src)\s*=\s*(["\'])\s*(?:javascript:|data:text\/html:).*?\2/i', '', $html) ?? '';
        $html = preg_replace_callback('#<style\b[^>]*>(.*?)</style\s*>#is', function (array $matches): string {
            $css = preg_replace('/@import\b[^;]*;?/i', '', $matches[1]) ?? '';
            $css = preg_replace('/expression\s*\([^)]*\)/i', '', $css) ?? '';
            $css = preg_replace('/url\(\s*(["\'])?\s*(?:javascript:|data:text\/html:).*?\)/i', 'none', $css) ?? '';

            return '<style>'.$css.'</style>';
        }, $html) ?? '';
        $allowedTags = ['html', 'head', 'body', 'title', 'meta', 'style', 'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col', 'div', 'span', 'hr', 'main', 'section', 'article', 'header', 'footer', 'small', 'sup', 'sub', 'img', 'font', 'a'];
        $allowedTagString = '<'.implode('><', $allowedTags).'>';
        $html = strip_tags($html, $allowedTagString);

        return preg_replace_callback('/<\s*(\/?)\s*([a-z0-9]+)\b([^>]*)>/i', function (array $matches) use ($allowedTags): string {
            $tag = strtolower($matches[2]);
            if (! in_array($tag, $allowedTags, true)) {
                return '';
            }
            if ($matches[1] === '/') {
                return "</{$tag}>";
            }

            $allowedAttributes = ['class', 'id', 'style', 'align', 'colspan', 'rowspan', 'width', 'height', 'src', 'href', 'alt', 'title', 'lang', 'dir', 'scope', 'border', 'cellpadding', 'cellspacing', 'face', 'size', 'color'];
            preg_match_all('/([a-z_:][-a-z0-9_:.]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $matches[3], $attributes, PREG_SET_ORDER);
            $safeAttributes = '';
            foreach ($attributes as $attribute) {
                $name = strtolower($attribute[1]);
                $value = $attribute[2] !== '' ? $attribute[2] : ($attribute[3] !== '' ? $attribute[3] : ($attribute[4] ?? ''));
                if (! in_array($name, $allowedAttributes, true)) {
                    continue;
                }
                if (in_array($name, ['src', 'href'], true) && preg_match('/^\s*(?:javascript:|data:text\/html:)/i', $value)) {
                    continue;
                }
                if ($name === 'style' && preg_match('/expression\s*\(|url\s*\(\s*["\']?\s*(?:javascript:|data:text\/html:)/i', $value)) {
                    continue;
                }
                $safeAttributes .= ' '.$name.'="'.e($value).'"';
            }

            return '<'.$tag.$safeAttributes.'>';
        }, $html) ?? '';
    }

    private function printableDocumentHtml(string $content, string $trackingNumber): string
    {
        $metadata = '<meta charset="utf-8"><title>'.e($trackingNumber).'</title>';
        if (preg_match('/<html\b/i', $content)) {
            if (! preg_match('/<meta\s+[^>]*charset/i', $content)) {
                $content = preg_replace('/<head\b[^>]*>/i', '$0<meta charset="utf-8">', $content, 1) ?? $content;
            }
            if (! preg_match('/<title\b/i', $content)) {
                $content = preg_replace('/<head\b[^>]*>/i', '$0<title>'.e($trackingNumber).'</title>', $content, 1) ?? $content;
            }

            return $content;
        }

        return '<!doctype html><html><head>'.$metadata.'</head><body>'.$content.'</body></html>';
    }

    public function generatedDocument(DocumentRequest $documentRequest)
    {
        abort_unless($documentRequest->generated_document_path, 404);

        return response(Storage::get($documentRequest->generated_document_path))
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Content-Security-Policy', "sandbox allow-same-origin; default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:");
    }

    public function documents()
    {
        return response()->json(Document::with(['requests:id,document_id', 'templates'])->orderBy('name')->get());
    }

    public function uploadDocumentTemplate(Request $request, Document $document)
    {
        $request->validate(['template' => ['required', 'file', 'mimes:docx', 'extensions:docx', 'max:10240']]);
        $file = $request->file('template');
        $zip = new \ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            throw ValidationException::withMessages(['template' => 'Upload a valid Word .docx file.']);
        }
        try {
            $size = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                $size += $entry['size'];
                if ($size > 50 * 1024 * 1024 || $zip->numFiles > 2000) {
                    throw new \RuntimeException('Template is too large to process.');
                }
                if (str_ends_with($entry['name'], '.rels')) {
                    $xml = new \DOMDocument;
                    if (!$xml->loadXML($zip->getFromIndex($index), LIBXML_NONET)) throw new \RuntimeException('Invalid Word relationships.');
                    foreach ($xml->getElementsByTagName('Relationship') as $relation) {
                        if (strtolower($relation->getAttribute('TargetMode')) === 'external') {
                            throw new \RuntimeException('Embed images in the Word file instead of linking external content.');
                        }
                    }
                }
            }
            if ($zip->locateName('word/document.xml') === false) throw new \RuntimeException('Invalid Word document.');
        } catch (\Throwable $error) {
            throw ValidationException::withMessages(['template' => $error->getMessage()]);
        } finally {
            $zip->close();
        }
        try {
            $word = \PhpOffice\PhpWord\IOFactory::load($file->getRealPath(), 'Word2007');
            $html = \PhpOffice\PhpWord\IOFactory::createWriter($word, 'HTML')->getContent();
            $body = $this->sanitizeDocumentHtml($html);
            if (strlen($body) > 500000) throw new \RuntimeException('Template content is too large for the editor.');
        } catch (\Throwable $error) {
            throw ValidationException::withMessages(['template' => 'Cannot read this Word template. Use a simpler .docx file with embedded images.']);
        }
        $path = $file->store('document-templates');
        try {
            $template = DocumentTemplate::create(['document_id' => $document->id, 'name' => $file->getClientOriginalName(), 'uploaded_path' => $path, 'body' => $body]);
        } catch (\Throwable $error) {
            Storage::delete($path);
            throw $error;
        }
        return response()->json(['message' => 'Word template published.', 'template' => $template], 201);
    }

    public function templateDraft(Document $document)
    {
        $template = $document->templates()->orderByDesc('id')->first();
        abort_unless($template, 422, 'Upload a Word template before editing this document.');
        return response()->json(['content' => $template->body, 'saved_at' => $template->updated_at?->toIso8601String()]);
    }

    public function saveTemplateDraft(Request $request, Document $document)
    {
        $data = $request->validate(['content' => ['required', 'string', 'max:500000']]);
        $template = $document->templates()->orderByDesc('id')->first();
        abort_unless($template, 422, 'Upload a Word template before editing this document.');
        // A new version keeps requests already submitted against the previous template intact.
        $updated = DocumentTemplate::create([
            'document_id' => $document->id,
            'name' => $template->name,
            'uploaded_path' => $template->uploaded_path,
            'body' => $this->sanitizeDocumentHtml($data['content']),
        ]);
        return response()->json(['saved_at' => $updated->updated_at->toIso8601String()]);
    }
    private function requestTemplate(DocumentRequest $documentRequest): ?DocumentTemplate
    {
        $query = DocumentTemplate::where('document_id', $documentRequest->document_id);
        if (array_key_exists('template_id', $documentRequest->document_snapshot ?? [])) {
            return $query->whereKey($documentRequest->document_snapshot['template_id'])->first();
        }
        return $query->orderByDesc('id')->first();
    }
    public function storeDocument(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string', 'max:2000'],
            'purpose' => ['required', 'string', 'max:2000'],
            'fee' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'requirements' => ['required', 'array', 'min:1'],
            'requirements.*' => ['required', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $data['slug'] = Str::slug($data['name']).'-'.Str::lower(Str::random(6));
        $data['requirements'] = array_values($data['requirements'] ?? []);
        $data['is_active'] = $data['is_active'] ?? true;

        return response()->json(Document::create($data), 201);
    }

    public function updateDocument(Request $request, Document $document)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'required', 'string', 'max:2000'],
            'purpose' => ['sometimes', 'required', 'string', 'max:2000'],
            'fee' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999'],
            'requirements' => ['sometimes', 'required', 'array', 'min:1'],
            'requirements.*' => ['required', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('requirements', $data)) {
            $data['requirements'] = array_values($data['requirements'] ?? []);
        }
        abort_if(($data['is_active'] ?? false) && ! $document->is_active, 422, 'Document activation is not part of the admin flow.');
        $document->update($data);

        return response()->json($document->fresh());
    }

    public function deleteDocument(Document $document)
    {
        abort_if($document->requests()->exists(), 409, 'Documents with request history cannot be deleted; deactivate them instead.');
        $document->delete();

        return response()->noContent();
    }

    public function residents(Request $request)
    {
        $residents = User::where('role', 'resident')
            ->where('verification_status', '!=', 'rejected')
            ->where('account_status', '!=', 'rejected')
            ->when($request->filled('verification_status'), fn ($query) => $query->where('verification_status', $request->string('verification_status')->toString()))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = $request->string('search')->toString();
                $query->where(fn ($builder) => $builder->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%"));
            })->latest()->paginate(30);

        return response()->json($residents);
    }

    public function residentNationalId(Request $request, User $resident)
    {
        $side = $request->validate(['side' => ['sometimes', 'in:front,back']])['side'] ?? 'front';
        $path = $side === 'back' ? $resident->national_id_back_path : $resident->national_id_path;
        abort_unless($resident->role === 'resident' && $path, 404);
        $mimeType = Storage::mimeType($path) ?? 'application/octet-stream';

        return Storage::response($path, 'national-id-'.$side, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    public function approveRegistration(User $resident)
    {
        abort_unless($resident->role === 'resident', 404);
        abort_unless($resident->verification_status === 'pending', 422, 'This registration has already been reviewed.');

        $resident->update([
            'verification_status' => 'verified',
            'account_status' => 'active',
        ]);
        ResidentNotification::create([
            'user_id' => $resident->id,
            'title' => 'Registration approved',
            'message' => 'Your registration and National ID have been approved. You can now sign in to eBaryo.',
        ]);

        return response()->json(['message' => 'Registration approved.', 'resident' => $resident->fresh()]);
    }

    public function rejectRegistration(Request $request, User $resident)
    {
        abort_unless($resident->role === 'resident', 404);
        abort_unless($resident->verification_status === 'pending', 422, 'This registration has already been reviewed.');
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $files = array_filter([$resident->national_id_path, $resident->national_id_back_path, $resident->profile_photo_path]);

        DB::transaction(function () use ($resident): void {
            // Pending registrations normally have no requests. Preserve document history if one exists.
            abort_if($resident->documentRequests()->exists(), 409, 'This account has document requests and cannot be deleted through registration review.');
            DB::table('sessions')->where('user_id', $resident->id)->delete();
            DB::table('password_reset_tokens')->where('email', $resident->email)->delete();
            $resident->delete();
        });

        foreach ($files as $file) {
            Storage::disk('local')->delete($file);
        }

        return response()->json(['message' => 'Registration rejected. The account has been permanently deleted.']);
    }
    public function sendNotification(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:3000'],
            'priority' => ['required', 'in:normal,important'],
            'recipient_type' => ['required', 'in:all,individual,selected'],
            'resident_ids' => ['exclude_if:recipient_type,all', 'required', 'array', 'min:1', $request->input('recipient_type') === 'individual' ? 'size:1' : 'min:1'],
            'resident_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where('role', 'resident')],
            'documents' => ['sometimes', 'array', 'max:5'],
            'documents.*' => ['file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:10240'],
        ]);
        $attachments = collect($request->file('documents', []))->map(fn ($file) => [
            'name' => $file->getClientOriginalName(),
            'path' => $file->store('notification-documents'),
        ])->all();
        $recipients = User::where('role', 'resident');
        if ($data['recipient_type'] !== 'all') {
            $recipients->whereIn('id', $data['resident_ids'] ?? []);
        }
        $rows = $recipients->pluck('id')->map(fn ($id) => [
            'user_id' => $id,
            'title' => $data['title'],
            'message' => $data['message'],
            'priority' => $data['priority'],
            'attachments' => $attachments ? json_encode($attachments) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();
        foreach (array_chunk($rows, 500) as $chunk) {
            ResidentNotification::insert($chunk);
        }

        return response()->json(['sent_to' => count($rows)], 201);
    }

    public function sentNotifications()
    {
        return response()->json(ResidentNotification::with('resident:id,name,email')->latest()->paginate(30));
    }

    public function reports()
    {
        return response()->json(DocumentRequest::with('resident:id,name')
            ->whereNull('archived_at')->latest('submitted_at')->paginate(25));
    }

    public function reportContent(DocumentRequest $documentRequest)
    {
        $documentRequest->update(['admin_read_at' => now()]);

        return response()->json($documentRequest->only(['id', 'document_snapshot', 'resident_snapshot', 'purpose', 'status', 'submitted_at']));
    }

}
