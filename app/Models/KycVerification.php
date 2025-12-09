<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KycVerification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'persona_inquiry_id',
        'status',
        'persona_data',
        'verified_at',
        'webhook_processed_at',
        'last_webhook_event_id',
    ];

    protected $casts = [
        'persona_data' => 'array',
        'verified_at' => 'datetime',
        'webhook_processed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

