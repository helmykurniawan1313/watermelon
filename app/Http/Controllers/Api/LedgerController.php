<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Ledger extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'currency_code'];

    /**
     * The users that belong to the ledger.
     */
    public function users()
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /**
     * ADD THIS: The accounts that belong to the ledger.
     */
    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

    /**
     * ADD THIS: The categories that belong to the ledger.
     */
    public function categories()
    {
        return $this->hasMany(Category::class);
    }

    /**
     * The transactions that belong to the ledger.
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}