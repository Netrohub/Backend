<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\User;

class ListingEventEmitter
{
    /**
     * Emit listing.created event
     */
    public static function created(Listing $listing): bool
    {
        $seller = $listing->user;
        
        $data = [
            'listing_id' => $listing->id,
            'id' => $listing->id, // Also include 'id' for backward compatibility
            'seller_id' => $seller->id,
            'seller_discord_id' => $seller->discord_user_id,
            'seller_username' => $seller->username,
            'title' => $listing->title,
            'description' => $listing->description, // Include description for Discord embed
            'category' => $listing->category,
            'price' => $listing->price,
            'images' => $listing->images ?? [], // Include images for Discord embed
            'status' => $listing->status,
            'created_at' => $listing->created_at->toIso8601String(),
        ];
        
        return DiscordEventEmitter::emit('listing.created', $data);
    }

    /**
     * Emit listing.updated event
     */
    public static function updated(Listing $listing, array $changes = []): bool
    {
        $seller = $listing->user;
        
        $data = [
            'listing_id' => $listing->id,
            'seller_id' => $seller->id,
            'seller_discord_id' => $seller->discord_user_id,
            'status' => $listing->status,
            'changes' => $changes,
            'updated_at' => $listing->updated_at->toIso8601String(),
        ];
        
        return DiscordEventEmitter::emit('listing.updated', $data);
    }

    /**
     * Emit listing.status_changed event
     */
    public static function statusChanged(Listing $listing, string $oldStatus, string $newStatus): bool
    {
        $seller = $listing->user;
        
        $data = [
            'listing_id' => $listing->id,
            'seller_id' => $seller->id,
            'seller_discord_id' => $seller->discord_user_id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'status' => $listing->status,
            'changed_at' => now()->toIso8601String(),
        ];
        
        return DiscordEventEmitter::emit('listing.status_changed', $data);
    }
}

