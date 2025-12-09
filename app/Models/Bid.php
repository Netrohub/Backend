<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bid extends Model
{
    use HasFactory;

    protected $fillable = [
        'auction_listing_id',
        'user_id',
        'amount',
        'deposit_amount',
        'deposit_status',
        'is_winning_bid',
        'is_outbid',
        'outbid_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'is_winning_bid' => 'boolean',
            'is_outbid' => 'boolean',
            'outbid_at' => 'datetime',
        ];
    }

    // Relationships
    public function auctionListing()
    {
        return $this->belongsTo(AuctionListing::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Scopes
    public function scopeWinning($query)
    {
        return $query->where('is_winning_bid', true);
    }

    public function scopeOutbid($query)
    {
        return $query->where('is_outbid', true);
    }

    public function scopePendingDeposit($query)
    {
        return $query->where('deposit_status', 'pending');
    }

    public function scopeHeldDeposit($query)
    {
        return $query->where('deposit_status', 'held');
    }
}

