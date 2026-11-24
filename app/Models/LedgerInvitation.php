<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LedgerInvitation extends Model
{
    protected $fillable = ['ledger_id', 'inviter_id', 'code', 'expires_at', 'accepted_by', 'accepted_at'];

    protected $casts = ['expires_at' => 'datetime', 'accepted_at' => 'datetime'];

    public function ledger()
    {
        return $this->belongsTo(Ledger::class);
    }

    public function inviter()
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    /**
     * 8 characters from an alphabet without look-alikes (no 0/O, 1/I/L), e.g. "K7MX-Q4PA".
     */
    public static function newCode(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (static::where('code', $code)->exists());

        return $code;
    }

    public function isUsable(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }
}
