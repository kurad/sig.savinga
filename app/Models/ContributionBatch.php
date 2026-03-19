<?php

namespace App\Models;

use App\Models\ContributionAllocation;
use App\Models\FinancialYearRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContributionBatch extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'beneficiary_id',
        'financial_year_rule_id',
        'total_amount',
        'paid_date',
        'start_period_key',
        'recorded_by',
        'reversed_at',
        'reversed_by',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'paid_date' => 'date',
        'reversed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function financialYearRule()
    {
        return $this->belongsTo(FinancialYearRule::class);
    }

    public function allocations()
    {
        return $this->hasMany(ContributionAllocation::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reversedBy()
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
    public function isReversed(): bool
    {
        return !is_null($this->reversed_at);
    }
}
