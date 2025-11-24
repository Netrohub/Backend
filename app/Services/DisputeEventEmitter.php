<?php

namespace App\Services;

use App\Models\Dispute;
use App\Models\Order;
use App\Models\User;

class DisputeEventEmitter
{
    /**
     * Emit dispute.created event
     */
    public static function created(Dispute $dispute): bool
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
            'buyer_username' => $buyer->username,
            'seller_username' => $seller->username,
            'initiated_by' => $dispute->initiated_by,
            'party' => $dispute->party,
            'reason' => $dispute->reason,
            'description' => $dispute->description,
            'status' => $dispute->status,
            'created_at' => $dispute->created_at->toIso8601String(),
        ];
        
        return DiscordEventEmitter::emit('dispute.created', $data);
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

