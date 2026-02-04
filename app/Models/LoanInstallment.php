<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoanInstallment extends Model
{
    use HasFactory;

    protected $fillable = [
        'loan_id',
        'installment_no',
        'due_date',
        'amount_due',
        'paid_amount',
        'status',
        'paid_date',
        'penalty_applied_at',
        'penalty_id',
    ];

    protected $casts = [
        'due_date' => 'date',
        'paid_date' => 'date',
        'penalty_applied_at' => 'datetime',
        'amount_due' => 'decimal:2',
        'paid_amount' => 'decimal:2',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function getRemainingAttribute(): float
    {
        return max(0, (float)$this->amount_due - (float)$this->paid_amount);
    }
}
