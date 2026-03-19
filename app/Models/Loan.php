<?php

namespace App\Models;

use App\Models\User;
use App\Models\LoanRepayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Loan extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'beneficiary_id',
        'base_loan_id',
        'principal',
        'interest_rate',
        'interest_basis',
        'interest_term_months',
        'interest_amount',
        'total_payable',
        'duration_months',
        'issued_date',
        'due_date',
        'status',
        'repayment_mode',
        'monthly_installment',
        'approved_by',
        'rate_set_by',
        'rate_set_at',
        'rate_notes',
    ];


    protected $casts = [
        'issued_date' => 'date',
        'due_date' => 'date',
        'rate_set_at' => 'datetime',
        'principal' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'interest_amount' => 'decimal:2',
        'total_payable' => 'decimal:2',
        'base_loan_id' => 'integer',
        'monthly_installment' => 'decimal:2',
    ];
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function repayments()
    {
        return $this->hasMany(LoanRepayment::class);
    }
    public function principalPaid(): float
    {
        return round((float) $this->repayments()->sum('principal_component'), 2);
    }
    public function guarantors()
    {
        return $this->hasMany(\App\Models\LoanGuarantor::class);
    }

    public function interestPaid(): float
    {
        return round((float) $this->repayments()->sum('interest_component'), 2);
    }

    public function outstandingPrincipal(): float
    {
        $principal = round((float) ($this->principal ?? 0), 2);
        return round(max(0, $principal - $this->principalPaid()), 2);
    }

    public function outstandingInterest(): float
    {
        // Prefer stored interest_amount if you have it, else derive it.
        $interestTotal = $this->interest_amount !== null
            ? round((float) $this->interest_amount, 2)
            : round(max(0, (float) $this->total_payable - (float) $this->principal), 2);

        return round(max(0, $interestTotal - $this->interestPaid()), 2);
    }

    public function outstandingBalance(): float
    {
        $totalPayable = round((float) ($this->total_payable ?? 0), 2);
        $paid = round((float) $this->totalRepaid(), 2);

        return round(max(0, $totalPayable - $paid), 2);
    }

    public function getInterestAttribute(): float
    {
        // Prefer stored snapshot if present
        if (!is_null($this->interest_amount) && (float)$this->interest_amount > 0) {
            return round((float)$this->interest_amount, 2);
        }

        // Fallback: derived but safe
        $derived = (float) $this->total_payable - (float) $this->principal;
        return round(max(0, $derived), 2);
    }

    public function totalRepaid(): float
    {
        return round((float) $this->repayments()->sum('amount'), 2);
    }

    public function installmentsPaidCount(): int
    {
        return (int) $this->installments()->where('status', 'paid')->count();
    }
    public function installments()
    {
        return $this->hasMany(\App\Models\LoanInstallment::class)->orderBy('installment_no');
    }
    public function nextUnpaidInstallment()
    {
        return $this->installments()->whereIn('status', ['unpaid', 'partial'])->orderBy('installment_no')->first();
    }
    public function baseLoan()
    {
        return $this->belongsTo(self::class, 'base_loan_id');
    }
    public function topUps()
    {
        return $this->hasMany(self::class, 'base_loan_id')->orderBy('created_at');
    }
    public function beneficiary()
    {
        return $this->belongsTo(Beneficiary::class);
    }
}
