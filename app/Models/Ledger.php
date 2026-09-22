<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ledger extends Model
{
    // Allow these fields to be saved in mass
    protected $fillable = ['name', 'currency'];

    /**
     * The users that belong to this ledger.
     */
    public function users()
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }
    
    /**
     * The bank accounts / wallets belonging to this ledger.
     * THIS WAS THE MISSING PIECE!
     */
    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

    /**
     * The categories (Food, Rent, etc.) belonging to this ledger.
     */
    public function categories()
    {
        return $this->hasMany(Category::class);
    }

    /**
     * The transactions recorded in this ledger.
     */
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Long-term installment plans.
     */
    public function installments()
    {
        return $this->hasMany(Installment::class);
    }
}