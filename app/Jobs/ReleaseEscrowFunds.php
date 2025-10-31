<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Wallet;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReleaseEscrowFunds implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $orderId
    ) {}

    public function handle(): void
    {
        $order = Order::find($this->orderId);

        if (!$order || $order->status !== 'escrow_hold') {
            Log::warning('Escrow release job skipped', ['order_id' => $this->orderId]);
            return;
        }

        // Check if order has dispute
        if ($order->dispute && $order->dispute->status !== 'closed') {
            Log::info('Escrow release blocked due to active dispute', ['order_id' => $this->orderId]);
            return;
        }

        // Release funds to seller
        $sellerWallet = Wallet::firstOrCreate(
            ['user_id' => $order->seller_id],
            ['available_balance' => 0, 'on_hold_balance' => 0, 'withdrawn_total' => 0]
        );

        $sellerWallet->lockForUpdate();
        $sellerWallet->available_balance += $order->amount;
        $sellerWallet->on_hold_balance -= $order->amount;
        $sellerWallet->save();

        // Update order status
        $order->status = 'completed';
        $order->completed_at = now();
        $order->save();

        Log::info('Escrow funds released', [
            'order_id' => $this->orderId,
            'seller_id' => $order->seller_id,
            'amount' => $order->amount,
        ]);
    }
}
