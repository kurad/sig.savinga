<?php

namespace App\Models;

use App\Models\FinancialYearRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MemberFinancialYear extends Model
{
    use HasFactory;

    protected $fillable = [
        'financial_year_rule_id',
        'user_id',
        'beneficiary_id',
        'opening_balance',
        'commitment_amount',
        'closing_balance',
        'closed_at',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'commitment_amount' => 'decimal:2',
        'closing_balance' => 'decimal:2',
        'closed_at' => 'datetime',
    ];

    public function yearRule()
    {
        return $this->belongsTo(FinancialYearRule::class, 'financial_year_rule_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
}
