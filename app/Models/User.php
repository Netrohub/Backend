<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use App\Models\KycVerification;
use App\Notifications\CustomVerifyEmail;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, MustVerifyEmailTrait;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'role',
        'is_verified',
        'avatar',
        'bio',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_verified' => 'boolean',
        ];
    }

    // Relationships
    public function listings()
    {
        return $this->hasMany(Listing::class);
    }

    public function ordersAsBuyer()
    {
        return $this->hasMany(Order::class, 'buyer_id');
    }

    public function ordersAsSeller()
    {
        return $this->hasMany(Order::class, 'seller_id');
    }

    public function disputes()
    {
        return $this->hasMany(Dispute::class, 'initiated_by');
    }

    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Reviews written by this user
     */
    public function reviewsGiven()
    {
        return $this->hasMany(\App\Models\Review::class, 'reviewer_id');
    }

    /**
     * Reviews received as a seller
     */
    public function reviewsReceived()
    {
        return $this->hasMany(\App\Models\Review::class, 'seller_id');
    }

    /**
     * Get average rating as a seller
     */
    public function getAverageRatingAttribute()
    {
        try {
            return round($this->reviewsReceived()->avg('rating') ?? 0, 1);
        } catch (\Exception $e) {
            // Reviews table doesn't exist yet
            return 0;
        }
    }

    /**
     * Get total reviews count as a seller
     */
    public function getTotalReviewsAttribute()
    {
        try {
            return $this->reviewsReceived()->count();
        } catch (\Exception $e) {
            // Reviews table doesn't exist yet
            return 0;
        }
    }

    public function kycVerification()
    {
        return $this->hasOne(KycVerification::class)->latestOfMany();
    }

    public function getIsVerifiedAttribute()
    {
        if (array_key_exists('is_verified', $this->attributes) && $this->attributes['is_verified']) {
            return (bool) $this->attributes['is_verified'];
        }

        return $this->kycVerification?->status === 'verified';
    }

    // Helper methods
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Send the email verification notification with custom frontend URL.
     */
    public function sendEmailVerificationNotification()
    {
        $this->notify(new CustomVerifyEmail);
    }
}
