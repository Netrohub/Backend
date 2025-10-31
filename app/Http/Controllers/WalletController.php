<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\MessageHelper;

class WalletController extends Controller
{
    public function index(Request $request)
    {
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $request->user()->id],
            [
                'available_balance' => 0,
                'on_hold_balance' => 0,
                'withdrawn_total' => 0,
            ]
        );

        return response()->json($wallet);
    }

    public function withdraw(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'bank_account' => 'required|string',
        ]);

        $wallet = Wallet::firstOrCreate(
            ['user_id' => $request->user()->id],
            [
                'available_balance' => 0,
                'on_hold_balance' => 0,
                'withdrawn_total' => 0,
            ]
        );

        // Wrap withdrawal in transaction to prevent race conditions
        return DB::transaction(function () use ($wallet, $validated) {
            // Reload wallet with lock to get fresh balance
            $wallet = Wallet::lockForUpdate()
                ->where('user_id', $wallet->user_id)
                ->firstOrFail();

            if ($wallet->available_balance < $validated['amount']) {
                return response()->json(['message' => MessageHelper::WALLET_INSUFFICIENT_BALANCE], 400);
            }

            $wallet->available_balance -= $validated['amount'];
            $wallet->withdrawn_total += $validated['amount'];
            $wallet->save();

            // TODO: Integrate with payment gateway (e.g., Tap Payments, bank transfer API)
            // This should:
            // 1. Call payment gateway API to initiate withdrawal
            // 2. Store withdrawal transaction ID
            // 3. Handle webhook callbacks for withdrawal status
            // 4. Update wallet status accordingly
            // 
            // For now, wallet balance is updated but actual bank transfer is not processed.
            // In production, implement actual withdrawal processing before marking as complete.

            return response()->json([
                'message' => MessageHelper::WALLET_WITHDRAWAL_SUBMITTED,
                'wallet' => $wallet->fresh(),
                'note' => 'Withdrawal processing will be completed via payment gateway integration',
            ]);
        });
    }
}
