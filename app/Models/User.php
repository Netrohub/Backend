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
        'username',
        'display_name',
        'email',
        'password',
        'phone',
        'persona_inquiry_id',
        'persona_reference_id',
        'kyc_status',
        'kyc_verified_at',
        'verified_phone',
        'phone_verified_at',
        'role',
        'is_verified',
        'avatar',
        'bio',
        'discord_user_id',
        'discord_username',
        'discord_avatar',
        'discord_connected_at',
        'is_seller',
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

    protected $appends = [
        'has_completed_kyc',
        'phone_verified',
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
            'kyc_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'discord_connected_at' => 'datetime',
            'is_seller' => 'boolean',
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

    public function getHasCompletedKycAttribute(): bool
    {
        return $this->kyc_status === 'verified';
    }

    public function getPhoneVerifiedAttribute(): bool
    {
        return !is_null($this->phone_verified_at);
    }

    /**
     * Get the official name (username if available, otherwise name)
     * This is the primary method to get the user's display name
     */
    public function getOfficialNameAttribute(): string
    {
        return $this->username ?? $this->name ?? '';
    }

    /**
     * Check if user has Discord connected
     */
    public function hasDiscord(): bool
    {
        return !is_null($this->discord_user_id);
    }

    /**
     * Generate a unique username from a base string
     */
    public static function generateUsername(string $base): string
    {
        // Normalize: lowercase, alphanumeric + underscore only
        $username = strtolower(preg_replace('/[^a-z0-9_]/', '', $base));
        
        // Ensure minimum length
        if (strlen($username) < 3) {
            $username = $username . str_pad('', 3 - strlen($username), '0');
        }
        
        // Truncate to max length
        if (strlen($username) > 20) {
            $username = substr($username, 0, 20);
        }
        
        // Ensure uniqueness
        $finalUsername = $username;
        $counter = 1;
        while (static::where('username', $finalUsername)->exists()) {
            $suffix = '_' . $counter;
            $maxLength = 20 - strlen($suffix);
            $finalUsername = substr($username, 0, $maxLength) . $suffix;
            $counter++;
            
            // Prevent infinite loop
            if ($counter > 9999) {
                $finalUsername = substr($username, 0, 10) . '_' . \Illuminate\Support\Str::random(6);
                break;
            }
        }
        
        return $finalUsername;
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
