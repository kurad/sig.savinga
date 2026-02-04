<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProfitCycle extends Model
{
    use HasFactory;

    public function distributions()
{
    return $this->hasMany(ProfitDistribution::class);
}
}



