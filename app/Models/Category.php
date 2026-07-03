<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $guarded = [];

    protected $fillable = [
    'name',
    'icon',
    'color',
    'type',
    'ledger_id' // <--- MUST be here
];

    public function ledger()
    {
        return $this->belongsTo(Ledger::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}