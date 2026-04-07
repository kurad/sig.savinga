<?php

namespace App\Models;

use App\Models\Loan;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class LoanMigrationSnapshot extends Model
{
    protected $fillable = [
        'loan_id',
        'original_principal',
        'original_total_payable',
        'principal_paid_before_migration',
        'interest_paid_before_migration',
        'outstanding_principal',
        'outstanding_interest',
        'migration_date',
        'note',
        'created_by',
    ];
     protected $casts = [
        'original_principal' => 'decimal:2',
        'original_total_payable' => 'decimal:2',
        'principal_paid_before_migration' => 'decimal:2',
        'interest_paid_before_migration' => 'decimal:2',
        'outstanding_principal' => 'decimal:2',
        'outstanding_interest' => 'decimal:2',
        'migration_date' => 'date',
    ];

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}