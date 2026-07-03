<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    // We use $fillable for better security. 
    // Removed $guarded to avoid conflicts.
    protected $fillable = [
        'ledger_id',
        'category_id',
        'account_id', // This allows the "Source of Funds" to be saved
        'user_id',
        'to_account_id',
        'installment_id',
        'amount',
        'date',
        'title',
        'type',
    ];

    protected $casts = [
        // Changed to 'datetime' so we keep the exact time you bought something
        'date' => 'datetime', 
    ];

    /**
     * The account (Bank/Wallet) this money came from or went to.
     */
    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function ledger()
    {
        return $this->belongsTo(Ledger::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
    
    public function installment()
    {
        return $this->belongsTo(Installment::class);
    }
    public function toAccount()
{
    return $this->belongsTo(Account::class, 'to_account_id');
}
// Add this to the bottom of your Transaction model
    protected function serializeDate(\DateTimeInterface $date)
    {
        // This strips the confusing timezone "Z" and sends a plain date
        return $date->format('Y-m-d H:i:s');
    }
}