<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentTemplate extends Model
{
    protected $fillable = ['document_id', 'name', 'body', 'uploaded_path'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
