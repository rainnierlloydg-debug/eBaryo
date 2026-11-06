<?php

namespace App\Services;

use App\Models\DocumentRequest;
use App\Models\ResidentNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestWorkflow
{
    private const TRANSITIONS = [
        'Submitted' => ['Under Review', 'Cancelled'],
        'Under Review' => ['Approved', 'Rejected', 'For Correction', 'Cancelled'],
        'Approved' => ['Processing', 'Cancelled'],
        'Processing' => ['Ready for Pickup', 'Cancelled'],
        'Ready for Pickup' => ['Released', 'Expired'],
    ];

    public function transition(DocumentRequest $record, User $actor, string $next, array $details = []): DocumentRequest
    {
        if (! in_array($next, self::TRANSITIONS[$record->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "The request cannot move from {$record->status} to {$next}."]);
        }

        if ($next === 'Rejected' && blank($details['reason'] ?? null)) {
            throw ValidationException::withMessages(['reason' => 'A rejection reason is required.']);
        }
        if ($next === 'For Correction' && (empty($details['fields']) || blank($details['instruction'] ?? null))) {
            throw ValidationException::withMessages(['instruction' => 'Select at least one field and provide correction instructions.']);
        }
        if ($next === 'Cancelled' && blank($details['reason'] ?? null)) {
            throw ValidationException::withMessages(['reason' => 'A cancellation reason is required.']);
        }

        return DB::transaction(function () use ($record, $actor, $next, $details): DocumentRequest {
            $updates = ['status' => $next];
            if ($next === 'Rejected') {
                $updates['rejection_reason'] = $details['reason'];
            } elseif ($next === 'For Correction') {
                $updates['correction_fields'] = $details['fields'];
                $updates['correction_instruction'] = $details['instruction'];
            } elseif ($next === 'Cancelled') {
                $updates['cancellation_reason'] = $details['reason'];
            } elseif ($next === 'Ready for Pickup') {
                $updates['pickup_deadline'] = now()->addDays(3);
            } elseif ($next === 'Released') {
                $updates['released_at'] = now();
            }

            $record->update($updates);
            $note = match ($next) {
                'Rejected' => $details['reason'],
                'For Correction' => $details['instruction'],
                'Cancelled' => $details['reason'],
                default => null,
            };
            $record->timeline()->create(['actor_id' => $actor->id, 'status' => $next, 'note' => $note]);

            ResidentNotification::create([
                'user_id' => $record->user_id,
                'title' => "Request {$next}",
                'message' => $this->message($record, $next, $details),
                'priority' => 'normal',
            ]);

            return $record->fresh();
        });
    }

    private function message(DocumentRequest $record, string $status, array $details): string
    {
        $message = match ($status) {
            'Approved' => "Your {$record->document_snapshot['name']} request {$record->tracking_number} has been approved.",
            'Rejected' => "Your request {$record->tracking_number} was rejected: {$details['reason']}",
            'For Correction' => "Your request {$record->tracking_number} needs correction: {$details['instruction']}",
            'Ready for Pickup' => "Your document is ready for pickup at the Barangay Hall. Please claim it within 3 days.",
            'Released' => "Your request {$record->tracking_number} has been released.",
            'Expired' => "Your request {$record->tracking_number} expired because it was not claimed within 3 days.",
            default => "Your request {$record->tracking_number} is now {$status}.",
        };

        return $message;
    }
}
