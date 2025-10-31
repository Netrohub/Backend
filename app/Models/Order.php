<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'listing_id',
        'buyer_id',
        'seller_id',
        'amount',
        'status',
        'tap_charge_id',
        'paid_at',
        'escrow_hold_at',
        'escrow_release_at',
        'completed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'escrow_hold_at' => 'datetime',
            'escrow_release_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    // Relationships
    public function listing()
    {
        return $this->belongsTo(Listing::class);
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function dispute()
    {
        return $this->hasOne(Dispute::class);
    }

    public function payment()
    {
        return $this->hasOne(Payment::class);
    }
}
