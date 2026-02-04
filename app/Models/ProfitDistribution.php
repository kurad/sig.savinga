<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProfitDistribution extends Model
{
    use HasFactory;


    public function cycle()
{
    return $this->belongsTo(ProfitCycle::class, 'profit_cycle_id');
}

public function user()
{
    return $this->belongsTo(User::class);
}

}
