<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequirementUpload extends Model
{
    protected $fillable = ['document_request_id', 'requirement_name', 'file_path', 'original_name', 'mime_type'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class, 'document_request_id');
    }
}
