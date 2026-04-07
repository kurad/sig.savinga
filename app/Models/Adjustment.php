<?php

namespace App\Models;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Adjustment extends Model
{
    protected $fillable = [
        'adjustable_type',
        'adjustable_id',
        'user_id',
        'beneficiary_id',
        'as_of_period',
        'amount',
        'reason',
        'transaction_id',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'float',
    ];

    public function adjustable(): MorphTo
    {
        return $this->morphTo();
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}