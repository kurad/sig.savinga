<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Beneficiary extends Model
{
    protected $fillable = [
        'guardian_user_id',
        'name',
        'date_of_birth',
        'relationship',
        'is_active',
        'joined_at',
        'registration_fee_required',
        'registration_fee_amount',
        'registration_fee_status',
        'registration_paid_at',
        'registration_recorded_by',
        'registration_note',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'joined_at' => 'date',
        'is_active' => 'boolean',
        'registration_fee_required' => 'boolean',
        'registration_paid_at' => 'datetime',
    ];

    public function guardian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guardian_user_id');
    }

    public function registrationRecorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registration_recorded_by');
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }

    public function penalties(): HasMany
    {
        return $this->hasMany(Penalty::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function commitments(): HasMany
    {
        return $this->hasMany(ContributionCommitment::class);
    }
}
