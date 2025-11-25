<?php

namespace App\Services;

use App\Models\Dispute;
use App\Models\Order;
use App\Models\User;

class DisputeEventEmitter
{
    /**
     * Emit dispute.created event and save thread_id
     */
    public static function created(Dispute $dispute): bool
    {
        $order = $dispute->order;
        $listing = $order->listing;
        $buyer = $order->buyer;
        $seller = $order->seller;
        
        $data = [
            'dispute_id' => $dispute->id,
            'order_id' => $order->id,
            'listing_id' => $listing->id ?? null,
            'category' => $listing->category ?? null,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'buyer_discord_id' => $buyer->discord_user_id,
            'seller_discord_id' => $seller->discord_user_id,
            'buyer_username' => $buyer->username,
            'seller_username' => $seller->username,
            'initiated_by' => $dispute->initiated_by,
            'party' => $dispute->party,
            'reason' => $dispute->reason,
            'description' => $dispute->description,
            'status' => $dispute->status,
            'created_at' => $dispute->created_at->toIso8601String(),
        ];
        
        $response = DiscordEventEmitter::emit('dispute.created', $data);
        
        // If response contains thread_id, save it to dispute
        if ($response && is_array($response) && isset($response['result'])) {
            $result = $response['result'];
            if (isset($result['thread_id']) && isset($result['channel_id'])) {
                $dispute->discord_thread_id = $result['thread_id'];
                $dispute->discord_channel_id = $result['channel_id'];
                // Save guild_id if provided (for building Discord URLs)
                if (isset($result['guild_id'])) {
                    // Store guild_id in a way we can access it later
                    // We can use discord_channel_id to get guild_id, but it's better to store it
                    // For now, we'll use the thread_url if provided, or build it from guild_id
                }
                $dispute->save();
            }
        }
        
        return $response !== false;
    }

    /**
     * Emit dispute.updated event
     */
    public static function updated(Dispute $dispute, array $changes = []): bool
    {
        $order = $dispute->order;
        $buyer = $order->buyer;
        $seller = $order->seller;
        
        $data = [
            'dispute_id' => $dispute->id,
            'order_id' => $order->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'buyer_discord_id' => $buyer->discord_user_id,
            'seller_discord_id' => $seller->discord_user_id,
            'status' => $dispute->status,
            'resolution' => $dispute->resolution,
            'resolution_notes' => $dispute->resolution_notes,
            'resolved_by' => $dispute->resolved_by,
            'resolved_at' => $dispute->resolved_at?->toIso8601String(),
            'changes' => $changes,
            'updated_at' => $dispute->updated_at->toIso8601String(),
        ];
        
        return DiscordEventEmitter::emit('dispute.updated', $data);
    }

    /**
     * Emit dispute.resolved event
     */
    public static function resolved(Dispute $dispute): bool
    {
        $order = $dispute->order;
        $buyer = $order->buyer;
        $seller = $order->seller;
        $resolver = $dispute->resolver;
        
        $data = [
            'dispute_id' => $dispute->id,
            'order_id' => $order->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'buyer_discord_id' => $buyer->discord_user_id,
            'seller_discord_id' => $seller->discord_user_id,
            'status' => $dispute->status,
            'resolution' => $dispute->resolution,
            'resolution_notes' => $dispute->resolution_notes,
            'resolved_by' => $dispute->resolved_by,
            'resolver_username' => $resolver?->username,
            'resolved_at' => $dispute->resolved_at->toIso8601String(),
        ];
        
        return DiscordEventEmitter::emit('dispute.resolved', $data);
    }
}

