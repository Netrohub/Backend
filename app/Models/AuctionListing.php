<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AuctionListing extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'listing_id',
        'user_id',
        'title',
        'description',
        'price',
        'category',
        'images',
        'account_metadata',
        'status',
        'starting_bid',
        'current_bid',
        'current_bidder_id',
        'starts_at',
        'ends_at',
        'bid_count',
        'admin_notes',
        'approved_by',
        'approved_at',
        'is_maxed_account',
    ];

    protected $hidden = [
        'account_email_encrypted',
        'account_password_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'images' => 'array',
            'account_metadata' => 'array',
            'starting_bid' => 'decimal:2',
            'current_bid' => 'decimal:2',
            'bid_count' => 'integer',
            'is_maxed_account' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    // Encryption/Decryption for account credentials
    public function setAccountEmailAttribute($value)
    {
        if ($value) {
            $this->attributes['account_email_encrypted'] = encrypt($value);
        }
    }

    public function getAccountEmailAttribute()
    {
        if (!isset($this->attributes['account_email_encrypted']) || empty($this->attributes['account_email_encrypted'])) {
            return null;
        }
        try {
            return decrypt($this->attributes['account_email_encrypted']);
        } catch (\Exception $e) {
            \Log::error('Failed to decrypt account email for auction listing: ' . $this->id);
            return null;
        }
    }

    public function setAccountPasswordAttribute($value)
    {
        if ($value) {
            $this->attributes['account_password_encrypted'] = encrypt($value);
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
            \Log::error('Failed to decrypt account password for auction listing: ' . $this->id);
            return null;
        }
    }

    // Relationships
    public function listing()
    {
        return $this->belongsTo(Listing::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function currentBidder()
    {
        return $this->belongsTo(User::class, 'current_bidder_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function bids()
    {
        return $this->hasMany(Bid::class)->orderBy('amount', 'desc')->orderBy('created_at', 'desc');
    }

    public function winningBid()
    {
        return $this->hasOne(Bid::class)->where('is_winning_bid', true)->latest();
    }

    // Scopes
    public function scopeLive($query)
    {
        return $query->where('status', 'live')
            ->where('ends_at', '>', now());
    }

    public function scopePendingApproval($query)
    {
        return $query->where('status', 'pending_approval');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeEnded($query)
    {
        return $query->where('status', 'ended');
    }

    // Helper methods
    public function isLive(): bool
    {
        return $this->status === 'live' && $this->ends_at && $this->ends_at->isFuture();
    }

    public function isEnded(): bool
    {
        return $this->status === 'ended' || ($this->ends_at && $this->ends_at->isPast());
    }

    public function timeRemaining(): ?\Carbon\Carbon
    {
        if (!$this->ends_at) {
            return null;
        }
        return $this->ends_at->isFuture() ? $this->ends_at : null;
    }

    public function getTimeRemainingInSeconds(): int
    {
        $remaining = $this->timeRemaining();
        return $remaining ? max(0, now()->diffInSeconds($remaining, false)) : 0;
    }
}

