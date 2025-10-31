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
    ];

    protected function casts(): array
    {
        return [
            'persona_data' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
