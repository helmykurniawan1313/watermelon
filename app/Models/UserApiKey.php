<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserApiKey extends Model
{
    use HasFactory;

    public const PROVIDERS = ['gemini', 'groq', 'openrouter'];

    protected $fillable = [
        'provider',
        'label',
        'api_key',
        'model',
        'is_active',
        'priority',
    ];

    protected $hidden = [
        'api_key',
    ];

    protected $casts = [
        'api_key' => 'encrypted',
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
        'last_failed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }
}
