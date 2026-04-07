<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpeningBalance extends Model
{
    protected $fillable = [
        'user_id',
        'beneficiary_id',
        'as_of_period',
        'amount',
        'note',
        'transaction_id',
        'created_by',
        'updated_by',
    ];
    public function adjustments()
    {
        return $this->morphMany(\App\Models\Adjustment::class, 'adjustable');
    }

    public function effectiveAmount(): float
    {
        return round((float) $this->amount + (float) $this->adjustments()->sum('amount'), 2);
    }
}
