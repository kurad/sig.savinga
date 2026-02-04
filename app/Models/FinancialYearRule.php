<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinancialYearRule extends Model
{
    use HasFactory;
    protected $fillable = [
        'year_key',
        'start_date',
        'end_date',
        'due_day',
        'grace_days',
        'is_active',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
    ];
}
