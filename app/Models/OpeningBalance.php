<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpeningBalance extends Model
{
    protected $fillable = [
        'user_id',
        'as_of_period',
        'amount',
        'note',
        'transaction_id',
        'created_by',
        'updated_by',
    ];
}
