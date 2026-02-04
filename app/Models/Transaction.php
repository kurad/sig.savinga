<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'debit',
        'credit',
        'reference',
        'source_type',
        'source_id',
        'created_by'
    ];
    protected $casts = [
        // Keep these as decimal strings with 2dp (avoids float rounding surprises)
        'debit'  => 'decimal:2',
        'credit' => 'decimal:2',
    ];
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
