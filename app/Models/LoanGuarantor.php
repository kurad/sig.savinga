<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanGuarantor extends Model
{
    protected $fillable = [
        'loan_id',
        'participant_type',
        'guarantor_user_id',
        'beneficiary_id',
        'pledged_amount',
        'status',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function guarantor()
    {
        return $this->belongsTo(User::class, 'guarantor_user_id');
    }

    public function beneficiary()
    {
        return $this->belongsTo(Beneficiary::class);
    }
}