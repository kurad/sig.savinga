<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'beneficiary_id',
        'type',
        'debit',
        'credit',
        'reference',
        'source_type',
        'source_id',
        'created_by',
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForOwner(Builder $query, int $userId, ?int $beneficiaryId = null): Builder
    {
        $query->where('user_id', $userId);

        if (is_null($beneficiaryId)) {
            return $query->whereNull('beneficiary_id');
        }

        return $query->where('beneficiary_id', $beneficiaryId);
    }

    public function scopeDebit(Builder $query): Builder
    {
        return $query->where('debit', '>', 0);
    }

    public function scopeCredit(Builder $query): Builder
    {
        return $query->where('credit', '>', 0);
    }

    public function isDebit(): bool
    {
        return (float) $this->debit > 0;
    }

    public function isCredit(): bool
    {
        return (float) $this->credit > 0;
    }

    public function isUserLevel(): bool
    {
        return is_null($this->beneficiary_id);
    }

    public function isBeneficiaryLevel(): bool
    {
        return !is_null($this->beneficiary_id);
    }
}