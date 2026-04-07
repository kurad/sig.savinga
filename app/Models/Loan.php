<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'is_migrated',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'beneficiary_id' => 'integer',
        'base_loan_id' => 'integer',
        'interest_term_months' => 'integer',
        'duration_months' => 'integer',

        'issued_date' => 'date',
        'due_date' => 'date',
        'rate_set_at' => 'datetime',

        'principal' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'interest_amount' => 'decimal:2',
        'total_payable' => 'decimal:2',
        'monthly_installment' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class);
    }

    public function guarantors(): HasMany
    {
        return $this->hasMany(LoanGuarantor::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class)->orderBy('installment_no');
    }

    public function baseLoan(): BelongsTo
    {
        return $this->belongsTo(self::class, 'base_loan_id');
    }

    public function topUps(): HasMany
    {
        return $this->hasMany(self::class, 'base_loan_id')->orderBy('created_at');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rateSetter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rate_set_by');
    }

    public function scopeForOwner(Builder $query, int $userId, ?int $beneficiaryId = null): Builder
    {
        $query->where('user_id', $userId);

        if (is_null($beneficiaryId)) {
            return $query->whereNull('beneficiary_id');
        }

        return $query->where('beneficiary_id', $beneficiaryId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    public function scopeDefaulted(Builder $query): Builder
    {
        return $query->where('status', 'defaulted');
    }

    public function scopeInstallmentMode(Builder $query): Builder
    {
        return $query->where('repayment_mode', 'installment');
    }

    public function scopeOnceMode(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNull('repayment_mode')
                ->orWhere('repayment_mode', 'once');
        });
    }

    public function principalPaid(): float
    {
        return round((float) $this->repayments()->sum('principal_component'), 2);
    }

    public function interestPaid(): float
    {
        return round((float) $this->repayments()->sum('interest_component'), 2);
    }

    public function totalRepaid(): float
    {
        return round((float) $this->repayments()->sum('amount'), 2);
    }

    public function outstandingPrincipal(): float
    {
        $principal = round((float) ($this->principal ?? 0), 2);
        return round(max(0, $principal - $this->principalPaid()), 2);
    }

    public function outstandingInterest(): float
    {
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
        if (!is_null($this->interest_amount) && (float) $this->interest_amount > 0) {
            return round((float) $this->interest_amount, 2);
        }

        $derived = (float) $this->total_payable - (float) $this->principal;
        return round(max(0, $derived), 2);
    }

    public function installmentsPaidCount(): int
    {
        return (int) $this->installments()->where('status', 'paid')->count();
    }

    public function nextUnpaidInstallment()
    {
        return $this->installments()
            ->whereIn('status', ['unpaid', 'partial'])
            ->orderBy('installment_no')
            ->first();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isDefaulted(): bool
    {
        return $this->status === 'defaulted';
    }

    public function isUserLevel(): bool
    {
        return is_null($this->beneficiary_id);
    }

    public function isBeneficiaryLevel(): bool
    {
        return !is_null($this->beneficiary_id);
    }

    public function isTopUp(): bool
    {
        return !is_null($this->base_loan_id);
    }
    public function migrationSnapshot()
    {
        return $this->hasOne(LoanMigrationSnapshot::class);
    }
    public function getMigratedOutstandingBalanceAttribute(): float
    {
        $snapshot = $this->migrationSnapshot;

        if (!$snapshot) {
            return (float) $this->total_payable;
        }

        $paidAfterMigration = (float) $this->repayments()->sum('amount');

        return max(
            0,
            ((float) $snapshot->outstanding_principal + (float) $snapshot->outstanding_interest) - $paidAfterMigration
        );
    }
    public function adjustments()
    {
        return $this->morphMany(\App\Models\Adjustment::class, 'adjustable');
    }

    public function effectivePrincipal(): float
    {
        return round((float) $this->principal + (float) $this->adjustments()->sum('amount'), 2);
    }
}
