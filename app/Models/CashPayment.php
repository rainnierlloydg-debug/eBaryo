<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashPayment extends Model
{
    protected $fillable = ['document_request_id', 'recorded_by', 'amount_paid', 'paid_at'];

    protected function casts(): array
    {
        return ['amount_paid' => 'decimal:2', 'paid_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class, 'document_request_id');
    }
}
