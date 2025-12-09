<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Image extends Model
{
    protected $fillable = [
        'user_id',
        'image_id',
        'filename',
        'url',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    /**
     * Get the user that uploaded the image
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the thumbnail URL
     */
    public function getThumbnailUrlAttribute(): string
    {
        $accountHash = config('services.cloudflare.account_hash');
        return "https://imagedelivery.net/{$accountHash}/{$this->image_id}/thumbnail";
    }

    /**
     * Get the medium variant URL
     */
    public function getMediumUrlAttribute(): string
    {
        $accountHash = config('services.cloudflare.account_hash');
        return "https://imagedelivery.net/{$accountHash}/{$this->image_id}/medium";
    }

    /**
     * Get the public (full size) URL
     */
    public function getPublicUrlAttribute(): string
    {
        return $this->url;
    }
}
