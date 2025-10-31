<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use Illuminate\Http\Request;

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

        if ($wallet->available_balance < $validated['amount']) {
            return response()->json(['message' => 'Insufficient balance'], 400);
        }

        $wallet->lockForUpdate();
        $wallet->available_balance -= $validated['amount'];
        $wallet->withdrawn_total += $validated['amount'];
        $wallet->save();

        // Here you would integrate with payment gateway to process withdrawal
        // For now, we'll just update the wallet

        return response()->json([
            'message' => 'Withdrawal request submitted',
            'wallet' => $wallet->fresh(),
        ]);
    }
}
