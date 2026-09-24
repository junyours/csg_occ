<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Department extends Model
{
    protected $fillable = ['name', 'code', 'logo_path'];

    // Mirrors Student::photo_url — the React app wants a ready-to-use
    // image URL, not a raw storage path, on every JSON response.
    protected $appends = ['logo_url'];

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    protected function logoUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null,
        );
    }
}
