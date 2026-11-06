<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\RequestTimeline;
use App\Models\ResidentNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RequestController extends Controller
{
    public function dashboard(Request $request)
    {
        $requests = $request->user()->documentRequests();

        return response()->json([
            'total_requests' => (clone $requests)->count(),
            'unread_notifications' => $request->user()->residentNotifications()->whereNull('read_at')->count(),
            'pending' => (clone $requests)->whereIn('status', ['Submitted', 'Under Review', 'For Correction'])->count(),
            'approved' => (clone $requests)->where('status', 'Approved')->count(),
            'ready_for_pickup' => (clone $requests)->where('status', 'Ready for Pickup')->count(),
            'completed' => (clone $requests)->where('status', 'Released')->count(),
            'recent_requests' => (clone $requests)->latest()->limit(5)->get(),
        ]);
    }

    public function documents(Request $request)
    {
        $documents = Document::withExists(['templates', 'templates as word_template_exists' => fn ($query) => $query->whereNotNull('uploaded_path')])
            ->where('is_active', true)
            ->when($request->string('search')->trim()->isNotEmpty(), function ($query) use ($request): void {
                $search = $request->string('search')->trim()->toString();
                $query->where('name', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->get();

        return response()->json($documents);
    }

    public function documentTemplate(Request $request, Document $document)
    {
        abort_unless($document->is_active, 404);
        $template = $document->templates()->orderByDesc('id')->firstOrFail();
        if ($request->boolean('download')) {
            abort_unless($template->uploaded_path, 404);
            return Storage::download($template->uploaded_path, $template->name, ['Cache-Control' => 'private, no-store']);
        }
        return response($template->body)->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'private, no-store')
            ->header('Content-Security-Policy', "sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src data:");
    }
    public function index(Request $request)
    {
        return response()->json($request->user()->documentRequests()
            ->with(['uploads', 'timeline.actor:id,name,role'])
            ->latest()->paginate(20));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'document_id' => ['required', 'integer', 'exists:documents,id'],
            'purpose' => ['required', 'string', 'max:500'],
            'additional_information' => ['required', 'array'],
            'additional_information.note' => ['required', 'string', 'max:1000'],
            'additional_information.*' => ['nullable', 'string', 'max:1000'],
            'requirements' => ['prohibited'],
        ]);

        $document = Document::query()->whereKey($data['document_id'])->where('is_active', true)->firstOrFail();
        $record = DB::transaction(function () use ($request, $data, $document) {
            $record = DocumentRequest::create([
                'tracking_number' => (string) Str::uuid(),
                'user_id' => $request->user()->id,
                'document_id' => $document->id,
                'document_snapshot' => array_merge($document->only(['name', 'description', 'purpose', 'fee', 'requirements']), ['template_id' => $document->templates()->orderByDesc('id')->value('id')]),
                'resident_snapshot' => $request->user()->only(['name', 'address', 'email']),
                'purpose' => $data['purpose'],
                'additional_information' => $data['additional_information'] ?? [],
                'status' => 'Submitted',
                'fee' => $document->fee,
                'payment_status' => 'Unpaid',
                'submitted_at' => now(),
            ]);
            $format = DB::table('system_settings')->where('key', 'document_number_format')->value('value');
            $trackingNumber = $format
                ? strtr($format, ['{year}' => now()->format('Y'), '{id}' => str_pad((string) $record->id, 6, '0', STR_PAD_LEFT)])
                : sprintf('BRGY-%s-%06d', now()->format('Y'), $record->id);
            $record->update(['tracking_number' => $trackingNumber]);

            $record->timeline()->create([
                'actor_id' => $request->user()->id,
                'status' => 'Submitted',
                'note' => 'Request submitted by resident.',
            ]);
            ResidentNotification::create([
                'user_id' => $request->user()->id,
                'title' => 'Request submitted',
                'message' => "Your {$document->name} request {$record->tracking_number} has been submitted.",
            ]);

            return $record->load(['uploads', 'timeline']);
        });

        return response()->json($record, 201);
    }

    public function cancel(Request $request, DocumentRequest $documentRequest)
    {
        abort_unless($documentRequest->user_id === $request->user()->id, 404);
        abort_unless($documentRequest->status === 'Under Review', 422, 'Only requests Under Review can be cancelled.');
        $data = $request->validate([
            'reason_type' => ['required', 'in:Submitted by mistake,No longer needed,Will submit later,Other'],
            'custom_reason' => ['required_if:reason_type,Other', 'nullable', 'string', 'max:1000'],
        ]);
        $reason = $data['reason_type'] === 'Other' ? $data['custom_reason'] : $data['reason_type'];

        DB::transaction(function () use ($request, $documentRequest, $reason): void {
            $documentRequest->update(['status' => 'Cancelled', 'cancellation_reason' => $reason]);
            $documentRequest->timeline()->create(['actor_id' => $request->user()->id, 'status' => 'Cancelled', 'note' => $reason]);
        });

        return response()->json($documentRequest->fresh());
    }

    public function resubmit(Request $request, DocumentRequest $documentRequest)
    {
        abort_unless($documentRequest->user_id === $request->user()->id, 404);
        abort_unless($documentRequest->status === 'For Correction', 422, 'This request is not awaiting corrections.');
        $data = $request->validate([
            'purpose' => ['sometimes', 'string', 'max:500'],
            'additional_information' => ['sometimes', 'array'],
            'requirements' => ['nullable', 'array'],
            'requirements.*' => ['array'],
            'requirements.*.*' => ['image', 'mimes:jpg,jpeg,png', 'max:8192'],
        ]);
        $allowedFields = $documentRequest->correction_fields ?? [];
        if (array_key_exists('purpose', $data) && ! in_array('purpose', $allowedFields, true)) {
            throw ValidationException::withMessages(['purpose' => 'Purpose was not requested for correction.']);
        }
        foreach (array_keys($data['additional_information'] ?? []) as $field) {
            if (! in_array("additional_information.{$field}", $allowedFields, true) && ! in_array($field, $allowedFields, true)) {
                throw ValidationException::withMessages(["additional_information.{$field}" => 'This field was not requested for correction.']);
            }
        }
        $uploads = $request->file('requirements', []);
        foreach (array_keys($uploads) as $index) {
            $requirement = $documentRequest->document_snapshot['requirements'][$index] ?? null;
            if (! $requirement || (! in_array($requirement, $allowedFields, true) && ! in_array("requirements.{$index}", $allowedFields, true))) {
                throw ValidationException::withMessages(["requirements.{$index}" => 'This requirement was not requested for correction.']);
            }
        }

        DB::transaction(function () use ($request, $documentRequest, $data, $uploads): void {
            $updates = ['status' => 'Submitted', 'correction_fields' => null, 'correction_instruction' => null];
            foreach (['purpose', 'additional_information'] as $field) {
                if (array_key_exists($field, $data)) {
                    $updates[$field] = $data[$field];
                }
            }
            $documentRequest->update($updates);
            foreach ($uploads as $index => $requirementUploads) {
                $name = $documentRequest->document_snapshot['requirements'][$index] ?? 'Supporting document';
                $previousUploads = $documentRequest->uploads()->where('requirement_name', $name)->get();
                foreach ($previousUploads as $previousUpload) {
                    Storage::delete($previousUpload->file_path);
                }
                $documentRequest->uploads()->where('requirement_name', $name)->delete();
                foreach ($requirementUploads as $upload) {
                    $documentRequest->uploads()->create([
                        'requirement_name' => $name,
                        'file_path' => $upload->store('requirements'),
                        'original_name' => $upload->getClientOriginalName(),
                        'mime_type' => $upload->getMimeType(),
                    ]);
                }
            }
            $documentRequest->timeline()->create(['actor_id' => $request->user()->id, 'status' => 'Submitted', 'note' => 'Resident resubmitted corrected information.']);
        });

        return response()->json($documentRequest->fresh()->load(['uploads', 'timeline']));
    }

    public function notifications(Request $request)
    {
        return response()->json($request->user()->residentNotifications()
            ->when($request->boolean('unread'), fn ($query) => $query->whereNull('read_at'))
            ->latest()->paginate(30));
    }

    public function unreadNotificationCount(Request $request)
    {
        return response()->json(['count' => $request->user()->residentNotifications()->whereNull('read_at')->count()]);
    }
    public function markNotificationRead(Request $request, ResidentNotification $notification)
    {
        abort_unless($notification->user_id === $request->user()->id, 404);
        $notification->update(['read_at' => now()]);

        return response()->json($notification);
    }

    public function notificationDocument(Request $request, ResidentNotification $notification, int $index)
    {
        abort_unless($request->user()->isAdmin() || $notification->user_id === $request->user()->id, 404);
        $file = $notification->attachments[$index] ?? null;
        abort_unless($file && Storage::exists($file['path']), 404);

        return Storage::download($file['path'], $file['name']);
    }

    public function markAllNotificationsRead(Request $request)
    {
        $request->user()->residentNotifications()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    public function upload(Request $request, int $uploadId)
    {
        $upload = DB::table('requirement_uploads')->where('id', $uploadId)->first();
        abort_unless($upload, 404);
        $documentRequest = DocumentRequest::findOrFail($upload->document_request_id);
        abort_unless($request->user()->isAdmin() || $documentRequest->user_id === $request->user()->id, 404);

        return Storage::download($upload->file_path, $upload->original_name);
    }
}
