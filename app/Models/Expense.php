<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use HasFactory;

    protected $fillable = [
        'amount', 'category', 'description', 'expense_date', 'recorded_by',
    ];

    protected $casts = [
        'expense_date' => 'date',
    ];
}
