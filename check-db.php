<?php

/**
 * Quick database connection check script
 * Run: php check-db.php
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Database Configuration Check ===\n\n";

echo "Default Connection: " . config('database.default') . "\n";
echo "Current Environment: " . config('app.env') . "\n\n";

try {
    $connection = DB::connection();
    echo "✓ Database Connection: SUCCESS\n";
    echo "  Database Name: " . $connection->getDatabaseName() . "\n";
    echo "  Driver: " . $connection->getDriverName() . "\n";
    
    // Check if cache table exists
    $cacheExists = Schema::hasTable('cache');
    echo "\nCache Table Exists: " . ($cacheExists ? "YES ✓" : "NO ✗") . "\n";
    
    // Check if users table exists
    $usersExists = Schema::hasTable('users');
    echo "Users Table Exists: " . ($usersExists ? "YES ✓" : "NO ✗") . "\n";
    
    if ($usersExists) {
        $userCount = DB::table('users')->count();
        echo "  Users Count: $userCount\n";
    }
    
} catch (\Exception $e) {
    echo "✗ Database Connection: FAILED\n";
    echo "  Error: " . $e->getMessage() . "\n";
}

echo "\n=== Check Complete ===\n";

