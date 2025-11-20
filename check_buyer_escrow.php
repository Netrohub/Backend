<?php

/**
 * Diagnostic script to check buyer wallet escrow balance
 * Run: php check_buyer_escrow.php [user_id]
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Wallet;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

$userId = $argv[1] ?? null;

if (!$userId) {
    echo "Usage: php check_buyer_escrow.php [user_id]\n";
    echo "Example: php check_buyer_escrow.php 1\n";
    exit(1);
}

echo "=== Buyer Wallet Escrow Diagnostic ===\n\n";
echo "User ID: $userId\n\n";

// Get wallet
$wallet = Wallet::where('user_id', $userId)->first();

if (!$wallet) {
    echo "❌ Wallet not found for user $userId\n";
    exit(1);
}

echo "Wallet Balance:\n";
echo "  Available Balance: $" . number_format($wallet->available_balance, 2) . "\n";
echo "  On Hold Balance: $" . number_format($wallet->on_hold_balance, 2) . "\n";
echo "  Withdrawn Total: $" . number_format($wallet->withdrawn_total, 2) . "\n\n";

// Get all orders for this buyer
$orders = Order::where('buyer_id', $userId)
    ->orderBy('created_at', 'desc')
    ->get();

echo "=== Orders Summary ===\n";
echo "Total Orders: " . $orders->count() . "\n\n";

// Group by status
$ordersByStatus = $orders->groupBy('status');

foreach ($ordersByStatus as $status => $statusOrders) {
    echo "Status: $status (" . $statusOrders->count() . " orders)\n";
    $totalAmount = $statusOrders->sum('amount');
    echo "  Total Amount: $" . number_format($totalAmount, 2) . "\n";
    
    foreach ($statusOrders as $order) {
        echo "  - Order #{$order->id}: $" . number_format($order->amount, 2);
        echo " (Created: " . $order->created_at->format('Y-m-d H:i:s') . ")";
        if ($order->paid_at) {
            echo " [Paid: " . $order->paid_at->format('Y-m-d H:i:s') . "]";
        }
        if ($order->completed_at) {
            echo " [Completed: " . $order->completed_at->format('Y-m-d H:i:s') . "]";
        }
        echo "\n";
    }
    echo "\n";
}

// Calculate expected escrow
$escrowHoldOrders = Order::where('buyer_id', $userId)
    ->where('status', 'escrow_hold')
    ->get();

$expectedEscrow = $escrowHoldOrders->sum('amount');

echo "=== Escrow Analysis ===\n";
echo "Orders in 'escrow_hold' status: " . $escrowHoldOrders->count() . "\n";
echo "Expected Escrow (sum of escrow_hold orders): $" . number_format($expectedEscrow, 2) . "\n";
echo "Actual Escrow (wallet on_hold_balance): $" . number_format($wallet->on_hold_balance, 2) . "\n";

$difference = $wallet->on_hold_balance - $expectedEscrow;
if (abs($difference) > 0.01) {
    echo "⚠️  MISMATCH: Difference of $" . number_format($difference, 2) . "\n";
} else {
    echo "✅ Escrow matches expected amount\n";
}

echo "\n=== Orders in Escrow Hold ===\n";
if ($escrowHoldOrders->isEmpty()) {
    echo "No orders in escrow_hold status.\n";
    echo "⚠️  If wallet shows escrow balance, there may be orphaned funds.\n";
} else {
    foreach ($escrowHoldOrders as $order) {
        echo "Order #{$order->id}:\n";
        echo "  Amount: $" . number_format($order->amount, 2) . "\n";
        echo "  Created: " . $order->created_at->format('Y-m-d H:i:s') . "\n";
        echo "  Paid At: " . ($order->paid_at ? $order->paid_at->format('Y-m-d H:i:s') : 'N/A') . "\n";
        echo "  Escrow Hold At: " . ($order->escrow_hold_at ? $order->escrow_hold_at->format('Y-m-d H:i:s') : 'N/A') . "\n";
        echo "  Escrow Release At: " . ($order->escrow_release_at ? $order->escrow_release_at->format('Y-m-d H:i:s') : 'N/A') . "\n";
        if ($order->escrow_release_at && $order->escrow_release_at->isPast()) {
            echo "  ⚠️  Escrow release time has passed - should have been auto-released\n";
        }
        echo "\n";
    }
}

// Check for completed orders that might have escrow issues
$completedOrders = Order::where('buyer_id', $userId)
    ->where('status', 'completed')
    ->get();

echo "=== Completed Orders ===\n";
echo "Total: " . $completedOrders->count() . "\n";
if ($completedOrders->isNotEmpty()) {
    echo "These orders should have had their escrow released:\n";
    foreach ($completedOrders->take(5) as $order) {
        echo "  - Order #{$order->id}: $" . number_format($order->amount, 2);
        echo " (Completed: " . ($order->completed_at ? $order->completed_at->format('Y-m-d H:i:s') : 'N/A') . ")\n";
    }
    if ($completedOrders->count() > 5) {
        echo "  ... and " . ($completedOrders->count() - 5) . " more\n";
    }
}

echo "\n=== Recommendations ===\n";
if ($escrowHoldOrders->isNotEmpty()) {
    echo "1. Buyer needs to confirm " . $escrowHoldOrders->count() . " order(s) to release escrow\n";
    echo "2. OR wait for automatic release after 12 hours (ReleaseEscrowFunds job)\n";
}

if (abs($difference) > 0.01 && $escrowHoldOrders->isEmpty()) {
    echo "⚠️  CRITICAL: Wallet shows escrow but no orders in escrow_hold status!\n";
    echo "   This indicates orphaned funds. Manual intervention required.\n";
}

echo "\n=== Diagnostic Complete ===\n";

