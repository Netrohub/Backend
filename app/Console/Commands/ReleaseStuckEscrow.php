<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Wallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReleaseStuckEscrow extends Command
{
    protected $signature = 'escrow:release-stuck {--user-id= : Specific user ID to check} {--dry-run : Show what would be released without actually releasing}';
    protected $description = 'Release escrow for orders that should have been auto-released';

    public function handle()
    {
        $userId = $this->option('user-id');
        $dryRun = $this->option('dry-run');

        $this->info('=== Stuck Escrow Release Tool ===');
        $this->newLine();

        // Find orders in escrow_hold that should have been released
        $query = Order::where('status', 'escrow_hold')
            ->whereNotNull('escrow_release_at')
            ->where('escrow_release_at', '<=', now());

        if ($userId) {
            $query->where('buyer_id', $userId);
        }

        $stuckOrders = $query->get();

        if ($stuckOrders->isEmpty()) {
            $this->info('✅ No stuck orders found.');
            if ($userId) {
                $this->info("   (Checked user ID: $userId)");
            }
            return 0;
        }

        $this->info("Found {$stuckOrders->count()} stuck order(s):");
        $this->newLine();

        $totalAmount = 0;
        foreach ($stuckOrders as $order) {
            $hoursPast = now()->diffInHours($order->escrow_release_at);
            $this->line("Order #{$order->id}:");
            $this->line("  Buyer ID: {$order->buyer_id}");
            $this->line("  Amount: $" . number_format($order->amount, 2));
            $this->line("  Escrow Release At: {$order->escrow_release_at}");
            $this->line("  Hours Past Due: {$hoursPast}");
            $this->newLine();
            $totalAmount += $order->amount;
        }

        $this->info("Total Amount to Release: $" . number_format($totalAmount, 2));
        $this->newLine();

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
            $this->info('Run without --dry-run to actually release escrow');
            return 0;
        }

        if (!$this->confirm('Do you want to release escrow for these orders?', true)) {
            $this->info('Cancelled.');
            return 0;
        }

        $released = 0;
        $failed = 0;

        foreach ($stuckOrders as $order) {
            try {
                DB::transaction(function () use ($order) {
                    // Reload order with lock
                    $order = Order::lockForUpdate()->find($order->id);
                    
                    // Double-check status
                    if ($order->status !== 'escrow_hold') {
                        throw new \Exception("Order #{$order->id} is no longer in escrow_hold status");
                    }

                    // Check for active dispute
                    if ($order->dispute && $order->dispute->status !== 'closed') {
                        throw new \Exception("Order #{$order->id} has an active dispute");
                    }

                    // Get buyer wallet
                    $buyerWallet = Wallet::lockForUpdate()
                        ->firstOrCreate(
                            ['user_id' => $order->buyer_id],
                            ['available_balance' => 0, 'on_hold_balance' => 0, 'withdrawn_total' => 0]
                        );

                    // Validate escrow balance
                    if ($buyerWallet->on_hold_balance < $order->amount) {
                        throw new \Exception("Insufficient escrow balance for order #{$order->id}");
                    }

                    // Release from buyer's escrow
                    $buyerWallet->on_hold_balance -= $order->amount;
                    $buyerWallet->save();

                    // Add to seller's available balance
                    $sellerWallet = Wallet::lockForUpdate()
                        ->firstOrCreate(
                            ['user_id' => $order->seller_id],
                            ['available_balance' => 0, 'on_hold_balance' => 0, 'withdrawn_total' => 0]
                        );

                    $sellerWallet->available_balance += $order->amount;
                    $sellerWallet->save();

                    // Update order status
                    $oldStatus = $order->status;
                    $order->status = 'completed';
                    $order->completed_at = now();
                    $order->save();

                    Log::info('Stuck escrow released manually', [
                        'order_id' => $order->id,
                        'buyer_id' => $order->buyer_id,
                        'seller_id' => $order->seller_id,
                        'amount' => $order->amount,
                        'escrow_release_at' => $order->escrow_release_at,
                        'hours_past_due' => now()->diffInHours($order->escrow_release_at),
                    ]);
                });

                $this->info("✅ Released escrow for Order #{$order->id}");
                $released++;
            } catch (\Exception $e) {
                $this->error("❌ Failed to release Order #{$order->id}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("=== Summary ===");
        $this->info("Released: $released");
        $this->info("Failed: $failed");

        return 0;
    }
}

