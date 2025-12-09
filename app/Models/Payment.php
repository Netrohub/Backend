<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'user_id',
        'tap_charge_id',
        'tap_reference',
        'paylink_transaction_no',
        'hyperpay_checkout_id',
        'paypal_order_id',
        'status',
        'amount',
        'currency',
        'tap_response',
        'paylink_response',
        'hyperpay_response',
        'paypal_response',
        'webhook_payload',
        'captured_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'tap_response' => 'array',
            'paylink_response' => 'array',
            'hyperpay_response' => 'array',
            'paypal_response' => 'array',
            'webhook_payload' => 'array',
            'captured_at' => 'datetime',
        ];
    }

    // Relationships
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
