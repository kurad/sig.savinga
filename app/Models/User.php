<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Loan;
use App\Models\Transaction;
use App\Models\Contribution;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'otp_code_hash',
        'otp_expires_at',
        'otp_attempts',
        'otp_last_sent_at',
        'role',
        'is_active',
        'joined_at'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function contributions(){
        return $this->hasMany(Contribution::class);
    }
    public function loans(){
        return $this->hasMany(Loan::class);
    }
    public function transactions() { 
        return $this->hasMany(Transaction::class); }

}
