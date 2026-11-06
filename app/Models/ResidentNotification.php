<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResidentNotification extends Model
{
    protected $fillable = ['user_id', 'title', 'message', 'priority', 'read_at', 'attachments'];

    protected $hidden = ['attachments'];

    protected $appends = ['documents'];

    public function getDocumentsAttribute(): array
    {
        return collect($this->attachments ?? [])->map(fn ($file, $index) => [
            'name' => $file['name'],
            'url' => '/api/notifications/'.$this->id.'/documents/'.$index,
        ])->all();
    }

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'attachments' => 'array'];
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
