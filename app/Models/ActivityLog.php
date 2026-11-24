<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['ledger_id', 'user_id', 'action', 'subject_type', 'subject_id', 'summary', 'meta'];

    protected $casts = ['meta' => 'array'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record one entry in a household's activity feed, attributed to the signed-in user.
     */
    public static function record(int $ledgerId, string $action, string $subjectType, ?int $subjectId, string $summary, array $meta = []): void
    {
        static::create([
            'ledger_id' => $ledgerId,
            'user_id' => Auth::id(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'summary' => mb_substr($summary, 0, 255),
            'meta' => $meta ?: null,
        ]);
    }

    public static function money($amount): string
    {
        return 'Rp ' . number_format((float) $amount, 0, ',', '.');
    }
}
