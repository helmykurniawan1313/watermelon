<?php

namespace App\Models;

// 1. MAKE SURE THIS LINE IS HERE
use Laravel\Sanctum\HasApiTokens; 
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    // 2. MAKE SURE HasApiTokens IS INSIDE THIS USE STATEMENT
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    // This is the relationship for your multi-user ledgers we built earlier
    public function ledgers()
    {
        return $this->belongsToMany(Ledger::class)->withPivot('role')->withTimestamps();
    }
}