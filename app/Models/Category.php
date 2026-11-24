<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = [
    'name',
    'icon',
    'color',
    'type',
    'monthly_budget',
    'ledger_id' // <--- MUST be here
];

    protected $casts = [
        'monthly_budget' => 'float',
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