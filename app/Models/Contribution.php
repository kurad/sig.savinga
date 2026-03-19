<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contribution extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'beneficiary_id',
        'financial_year_rule_id',
        'period_key',
        'amount',
        'expected_date',
        'paid_date',
        'status',
        'penalty_amount',
        'recorded_by',
    ];
    protected $casts = [
        'expected_date' => 'date',
        'paid_date' => 'date',
    ];
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function financialYearRule()
    {
        return $this->belongsTo(FinancialYearRule::class, 'financial_year_rule_id');
    }
    public function allocations()
    {
        return $this->hasMany(\App\Models\ContributionAllocation::class);
    }

    public function beneficiary()
    {
        return $this->belongsTo(Beneficiary::class);
    }
}
