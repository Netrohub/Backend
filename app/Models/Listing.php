<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Listing extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'price',
        'category',
        'images',
        'status',
        'views',
        'account_metadata',
        'verification_code',
        'verification_screenshot',
        'verification_approved',
        'verified_at',
        'verified_by',
    ];

    protected $hidden = [
        'account_email_encrypted',
        'account_password_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'price' => 'decimal:2',
            'views' => 'integer',
            'account_metadata' => 'array',
            'verification_approved' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    // Encryption/Decryption for account credentials
    // These are ONLY accessible to: owner, buyer after purchase, admin

    public function setAccountEmailAttribute($value)
    {
        $this->attributes['account_email_encrypted'] = encrypt($value);
    }

    public function setAccountPasswordAttribute($value)
    {
        $this->attributes['account_password_encrypted'] = encrypt($value);
    }

    public function getAccountEmailAttribute()
    {
        if (!isset($this->attributes['account_email_encrypted']) || empty($this->attributes['account_email_encrypted'])) {
            return null;
        }
        try {
            return decrypt($this->attributes['account_email_encrypted']);
        } catch (\Exception $e) {
            \Log::error('Failed to decrypt account email for listing: ' . $this->id);
            return null;
        }
    }

    public function getAccountPasswordAttribute()
    {
        if (!isset($this->attributes['account_password_encrypted']) || empty($this->attributes['account_password_encrypted'])) {
            return null;
        }
        try {
            return decrypt($this->attributes['account_password_encrypted']);
        } catch (\Exception $e) {
            \Log::error('Failed to decrypt account password for listing: ' . $this->id);
            return null;
        }
    }

    /**
     * Check if user can access account credentials
     */
    public function canAccessCredentials($user)
    {
        if (!$user) {
            return false;
        }

        // Owner can always access
        if ($this->user_id === $user->id) {
            return true;
        }

        // Admin can access
        if ($user->isAdmin()) {
            return true;
        }

        // Buyer can access if they have completed order
        $completedOrder = \App\Models\Order::where('listing_id', $this->id)
            ->where('buyer_id', $user->id)
            ->where('status', 'completed')
            ->exists();

        return $completedOrder;
    }

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    /**
     * Get listings only from non-deleted users
     * Use this scope when displaying public listings to exclude those from deleted users
     */
    public function scopeFromActiveUsers($query)
    {
        return $query->whereHas('user', function ($q) {
            $q->withoutTrashed();
        });
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
