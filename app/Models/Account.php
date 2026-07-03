<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    // These MUST be listed here or Laravel will ignore them during a 'create' call
    protected $fillable = [
        'ledger_id', 
        'name', 
        'balance', 
        'icon', 
        'color'
    ];

    public function ledger()
    {
        return $this->belongsTo(Ledger::class);
    }
}