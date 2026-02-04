<?php

namespace App\Models;

use App\Models\Loan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LoanRepayment extends Model
{
    use HasFactory;

    protected $fillable = [
    'loan_id',
    'amount',
    'principal_component',
    'interest_component',
    'repayment_date',
    'recorded_by',
];

protected $casts = [
    'repayment_date' => 'date',
];

public function loan()
    {
        return $this->belongsTo(Loan::class);
    }
}
