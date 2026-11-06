<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentRequest extends Model
{
    protected $fillable = [
        'tracking_number', 'user_id', 'document_id', 'document_snapshot', 'resident_snapshot', 'purpose',
        'additional_information', 'status', 'fee', 'payment_status', 'rejection_reason', 'archived_at', 'admin_read_at',
        'correction_fields', 'correction_instruction', 'cancellation_reason', 'submitted_at',
        'generated_document_path', 'document_draft', 'document_draft_saved_at', 'pickup_deadline', 'released_at',
    ];

    protected function casts(): array
    {
        return [
            'document_snapshot' => 'array',
            'resident_snapshot' => 'array',
            'additional_information' => 'array',
            'correction_fields' => 'array',
            'fee' => 'decimal:2',
            'submitted_at' => 'datetime',
            'document_draft_saved_at' => 'datetime',
            'pickup_deadline' => 'datetime',
            'released_at' => 'datetime',
            'archived_at' => 'datetime',
            'admin_read_at' => 'datetime',
        ];
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function uploads(): HasMany
    {
        return $this->hasMany(RequirementUpload::class);
    }

    public function timeline(): HasMany
    {
        return $this->hasMany(RequestTimeline::class)->orderBy('created_at');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CashPayment::class);
    }
}
