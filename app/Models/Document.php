<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'purpose', 'fee', 'requirements', 'is_active'];

    protected function casts(): array
    {
        return ['fee' => 'decimal:2', 'requirements' => 'array', 'is_active' => 'boolean'];
    }

    public function requests(): HasMany
    {
        return $this->hasMany(DocumentRequest::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(DocumentTemplate::class);
    }
}
