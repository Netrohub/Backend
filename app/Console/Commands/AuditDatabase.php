<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditDatabase extends Command
{
    protected $signature = 'db:audit';
    protected $description = 'Audit database for issues and duplications';

    public function handle()
    {
        $this->info('🔍 Starting Database Audit...');
        $this->newLine();
        
        $issues = [];
        
        // 1. Check for duplicate users (same email)
        $duplicateEmails = DB::table('users')
            ->select('email', DB::raw('count(*) as count'))
            ->groupBy('email')
            ->having('count', '>', 1)
            ->get();
        
        if ($duplicateEmails->count() > 0) {
            $issues[] = [
                'type' => 'DUPLICATE_EMAILS',
                'severity' => 'HIGH',
                'message' => 'Found users with duplicate emails',
                'count' => $duplicateEmails->count(),
                'details' => $duplicateEmails->toArray()
            ];
        }
        
        // 2. Check for orphaned listings
        $orphanedListings = DB::table('listings')
            ->leftJoin('users', 'listings.user_id', '=', 'users.id')
            ->whereNull('users.id')
            ->count();
        
        if ($orphanedListings > 0) {
            $issues[] = [
                'type' => 'ORPHANED_LISTINGS',
                'severity' => 'MEDIUM',
                'message' => 'Found listings without valid users',
                'count' => $orphanedListings
            ];
        }
        
        // 3. Check for orphaned orders
        $orphanedOrders = DB::table('orders')
            ->leftJoin('listings', 'orders.listing_id', '=', 'listings.id')
            ->whereNull('listings.id')
            ->count();
        
        if ($orphanedOrders > 0) {
            $issues[] = [
                'type' => 'ORPHANED_ORDERS',
                'severity' => 'MEDIUM',
                'message' => 'Found orders without valid listings',
                'count' => $orphanedOrders
            ];
        }
        
        // 4. Check for orders with invalid buyer/seller
        $invalidBuyers = DB::table('orders')
            ->leftJoin('users as buyers', 'orders.buyer_id', '=', 'buyers.id')
            ->whereNull('buyers.id')
            ->count();
        
        $invalidSellers = DB::table('orders')
            ->leftJoin('users as sellers', 'orders.seller_id', '=', 'sellers.id')
            ->whereNull('sellers.id')
            ->count();
        
        if ($invalidBuyers > 0 || $invalidSellers > 0) {
            $issues[] = [
                'type' => 'INVALID_ORDER_USERS',
                'severity' => 'HIGH',
                'message' => 'Found orders with invalid buyer or seller',
                'invalid_buyers' => $invalidBuyers,
                'invalid_sellers' => $invalidSellers
            ];
        }
        
        // 5. Check for duplicate listings (same title and user within 24 hours)
        $duplicateListings = DB::table('listings')
            ->select('user_id', 'title', DB::raw('count(*) as count'))
            ->where('created_at', '>', now()->subHours(24))
            ->groupBy('user_id', 'title')
            ->having('count', '>', 1)
            ->get();
        
        if ($duplicateListings->count() > 0) {
            $issues[] = [
                'type' => 'DUPLICATE_LISTINGS',
                'severity' => 'LOW',
                'message' => 'Found potential duplicate listings',
                'count' => $duplicateListings->count()
            ];
        }
        
        // 6. Check for listings with invalid status
        $invalidStatus = DB::table('listings')
            ->whereNotIn('status', ['active', 'sold', 'inactive'])
            ->count();
        
        if ($invalidStatus > 0) {
            $issues[] = [
                'type' => 'INVALID_LISTING_STATUS',
                'severity' => 'MEDIUM',
                'message' => 'Found listings with invalid status',
                'count' => $invalidStatus
            ];
        }
        
        // 7. Check for orders with invalid status
        $invalidOrderStatus = DB::table('orders')
            ->whereNotIn('status', ['pending', 'paid', 'escrow_hold', 'completed', 'cancelled', 'disputed'])
            ->count();
        
        if ($invalidOrderStatus > 0) {
            $issues[] = [
                'type' => 'INVALID_ORDER_STATUS',
                'severity' => 'MEDIUM',
                'message' => 'Found orders with invalid status',
                'count' => $invalidOrderStatus
            ];
        }
        
        // 8. Check for wallets without users
        $orphanedWallets = DB::table('wallets')
            ->leftJoin('users', 'wallets.user_id', '=', 'users.id')
            ->whereNull('users.id')
            ->count();
        
        if ($orphanedWallets > 0) {
            $issues[] = [
                'type' => 'ORPHANED_WALLETS',
                'severity' => 'MEDIUM',
                'message' => 'Found wallets without valid users',
                'count' => $orphanedWallets
            ];
        }
        
        // 9. Check for disputes without valid orders
        $orphanedDisputes = DB::table('disputes')
            ->leftJoin('orders', 'disputes.order_id', '=', 'orders.id')
            ->whereNull('orders.id')
            ->count();
        
        if ($orphanedDisputes > 0) {
            $issues[] = [
                'type' => 'ORPHANED_DISPUTES',
                'severity' => 'MEDIUM',
                'message' => 'Found disputes without valid orders',
                'count' => $orphanedDisputes
            ];
        }
        
        // 10. Check for users with missing required fields
        $usersWithoutEmail = DB::table('users')
            ->where(function($query) {
                $query->whereNull('email')->orWhere('email', '');
            })
            ->count();
        
        if ($usersWithoutEmail > 0) {
            $issues[] = [
                'type' => 'USERS_WITHOUT_EMAIL',
                'severity' => 'HIGH',
                'message' => 'Found users without email addresses',
                'count' => $usersWithoutEmail
            ];
        }
        
        // 11. Check for listings with negative prices
        $negativePrices = DB::table('listings')
            ->where('price', '<', 0)
            ->count();
        
        if ($negativePrices > 0) {
            $issues[] = [
                'type' => 'NEGATIVE_PRICES',
                'severity' => 'MEDIUM',
                'message' => 'Found listings with negative prices',
                'count' => $negativePrices
            ];
        }
        
        // Print results
        $this->info('📊 Audit Results:');
        $this->line('==================');
        $this->newLine();
        
        if (empty($issues)) {
            $this->info('✅ No issues found! Database is clean.');
        } else {
            $this->warn('⚠️  Found ' . count($issues) . ' issue(s):');
            $this->newLine();
            
            foreach ($issues as $issue) {
                $severityColor = match($issue['severity']) {
                    'HIGH' => 'error',
                    'MEDIUM' => 'warn',
                    'LOW' => 'comment',
                    default => 'info'
                };
                
                $this->{$severityColor}("[{$issue['severity']}] {$issue['type']}");
                $this->line("   {$issue['message']}");
                
                if (isset($issue['count'])) {
                    $this->line("   Count: {$issue['count']}");
                }
                
                if (isset($issue['details'])) {
                    $this->line("   Details: " . json_encode($issue['details'], JSON_PRETTY_PRINT));
                }
                
                $this->newLine();
            }
        }
        
        return count($issues) === 0 ? 0 : 1;
    }
}

