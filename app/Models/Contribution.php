<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contribution extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'amount',
        'expected_date',
        'paid_date',
        'status',
        'penalty_amount',
        'recorded_by',
        'period_key',
    ];
    protected $casts = [
        'expected_date' => 'date',
        'paid_date' => 'date',
    ];
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
