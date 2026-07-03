<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Installment extends Model
{
   protected $fillable = [
    'title', 
    'base_price', 
    'duration_months', 
    'interest_rate', 
    'monthly_amount', 
    'total_amount', // <--- Make sure this is here!
    'start_date', 
    'account_id', 
    'category_id', 
    'ledger_id',
    'paid_amount'
];
    public function ledger() { return $this->belongsTo(Ledger::class); }
    public function account() { return $this->belongsTo(Account::class); }
    public function category() { return $this->belongsTo(Category::class); }
}
