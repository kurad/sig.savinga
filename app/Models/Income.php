<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Income extends Model
{
    use HasFactory;

    protected $fillable = [
        'amount',
        'category',
        'description',
        'income_date',
        'recorded_by',
        'user_id',
        'beneficiary_id',
    ];

    protected $casts = [
        'income_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function beneficiary()
    {
        return $this->belongsTo(Beneficiary::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
