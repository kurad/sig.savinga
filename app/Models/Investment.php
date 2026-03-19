<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Investment extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'total_amount',
        'invested_date',
        'status',
        'sale_date',
        'sale_amount',
        'profit_loss',
        'recorded_by',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'sale_amount' => 'decimal:2',
        'profit_loss' => 'decimal:2',
        'invested_date' => 'date',
        'sale_date' => 'date',
    ];

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}