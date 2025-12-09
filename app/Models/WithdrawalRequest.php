<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WithdrawalRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'wallet_id',
        'amount', // Requested amount (before fee)
        'fee_amount', // Fee amount deducted
        'fee_percentage', // Fee percentage used
        'net_amount', // Net amount after fee (amount - fee_amount)
        'bank_account', // Legacy field (kept for backward compatibility)
        'iban', // IBAN (primary field)
        'bank_name',
        'account_holder_name',
        'status',
        'tap_transfer_id',
        'failure_reason',
        'tap_response',
        'order_breakdown', // JSON array of contributing orders
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'fee_percentage' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'tap_response' => 'array',
            'order_breakdown' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
}
