<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\MessageHelper;
use App\Helpers\AuditHelper;

class WalletController extends Controller
{
    // Withdrawal limits for security and fraud prevention
    const MIN_WITHDRAWAL_AMOUNT = 10; // $10 minimum
    const MAX_SINGLE_WITHDRAWAL = 2000; // $2,000 per transaction
    const DAILY_WITHDRAWAL_LIMIT = 5000; // $5,000 per day
    const HOURLY_WITHDRAWAL_LIMIT = 3; // 3 withdrawals per hour
    
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

    /**
     * Get withdrawal history for user
     */
    public function withdrawalHistory(Request $request)
    {
        $withdrawals = WithdrawalRequest::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($withdrawal) {
                return [
                    'id' => $withdrawal->id,
                    'amount' => $withdrawal->amount,
                    'iban' => $this->maskBankAccount($withdrawal->iban ?? $withdrawal->bank_account ?? ''),
                    'iban_full' => $withdrawal->iban ?? $withdrawal->bank_account ?? '', // For display in details
                    'bank_account' => $this->maskBankAccount($withdrawal->bank_account ?? $withdrawal->iban ?? ''), // Legacy field for backward compatibility
                    'bank_name' => $withdrawal->bank_name,
                    'account_holder_name' => $withdrawal->account_holder_name,
                    'status' => $withdrawal->status,
                    'tap_transfer_id' => $withdrawal->tap_transfer_id,
                    'failure_reason' => $withdrawal->failure_reason,
                    'created_at' => $withdrawal->created_at->toIso8601String(),
                    'processed_at' => $withdrawal->processed_at?->toIso8601String(),
                ];
            });

        return response()->json($withdrawals);
    }

    /**
     * Mask bank account for security (show first 4 and last 4 digits)
     */
    private function maskBankAccount($bankAccount): string
    {
        if (strlen($bankAccount) <= 8) {
            return $bankAccount;
        }
        
        return substr($bankAccount, 0, 6) . str_repeat('*', strlen($bankAccount) - 10) . substr($bankAccount, -4);
    }

    public function withdraw(Request $request)
    {
        $validated = $request->validate([
            'amount' => [
                'required',
                'numeric',
                'min:' . self::MIN_WITHDRAWAL_AMOUNT,
                'max:' . self::MAX_SINGLE_WITHDRAWAL,
            ],
            'iban' => [
                'required',
                'string',
                'regex:/^SA\d{22}$/', // Saudi IBAN format
            ],
            'bank_name' => [
                'required',
                'string',
                'max:255',
            ],
            'account_holder_name' => [
                'required',
                'string',
                'max:255',
            ],
        ], [
            'amount.min' => 'الحد الأدنى للسحب هو $' . self::MIN_WITHDRAWAL_AMOUNT,
            'amount.max' => 'الحد الأقصى للسحب هو $' . self::MAX_SINGLE_WITHDRAWAL . ' لكل عملية',
            'iban.required' => 'رقم الآيبان مطلوب',
            'iban.regex' => 'رقم الآيبان يجب أن يكون بصيغة IBAN السعودي (SA + 22 رقم)',
            'bank_name.required' => 'اسم البنك مطلوب',
            'account_holder_name.required' => 'اسم صاحب الحساب مطلوب',
        ]);

        // Check hourly withdrawal limit (additional protection beyond rate limiting)
        $recentWithdrawals = WithdrawalRequest::where('user_id', $request->user()->id)
            ->where('created_at', '>', now()->subHour())
            ->count();

        if ($recentWithdrawals >= self::HOURLY_WITHDRAWAL_LIMIT) {
            return response()->json([
                'message' => 'لقد تجاوزت الحد الأقصى من طلبات السحب. يرجى المحاولة بعد ساعة.',
                'error_code' => 'WITHDRAWAL_HOURLY_LIMIT_EXCEEDED',
                'limit' => self::HOURLY_WITHDRAWAL_LIMIT,
            ], 429);
        }

        // Check daily withdrawal limit
        $todayWithdrawals = WithdrawalRequest::where('user_id', $request->user()->id)
            ->whereDate('created_at', today())
            ->whereIn('status', [
                WithdrawalRequest::STATUS_PENDING,
                WithdrawalRequest::STATUS_PROCESSING,
                WithdrawalRequest::STATUS_COMPLETED
            ])
            ->sum('amount');

        if ($todayWithdrawals + $validated['amount'] > self::DAILY_WITHDRAWAL_LIMIT) {
            $remaining = max(0, self::DAILY_WITHDRAWAL_LIMIT - $todayWithdrawals);
            return response()->json([
                'message' => 'لقد تجاوزت الحد الأقصى اليومي للسحب ($' . self::DAILY_WITHDRAWAL_LIMIT . ')',
                'error_code' => 'DAILY_WITHDRAWAL_LIMIT_EXCEEDED',
                'daily_limit' => self::DAILY_WITHDRAWAL_LIMIT,
                'withdrawn_today' => (float) $todayWithdrawals,
                'remaining' => (float) $remaining,
            ], 400);
        }

        $wallet = Wallet::firstOrCreate(
            ['user_id' => $request->user()->id],
            [
                'available_balance' => 0,
                'on_hold_balance' => 0,
                'withdrawn_total' => 0,
            ]
        );

        // Wrap withdrawal in transaction to prevent race conditions
        return DB::transaction(function () use ($wallet, $validated, $request) {
            // Reload wallet with lock to get fresh balance
            $wallet = Wallet::lockForUpdate()
                ->where('user_id', $wallet->user_id)
                ->firstOrFail();

            if ($wallet->available_balance < $validated['amount']) {
                return response()->json(['message' => MessageHelper::WALLET_INSUFFICIENT_BALANCE], 400);
            }

            // Calculate order breakdown - which orders contributed to this withdrawal
            // Get user's completed orders (where they were seller) ordered by completion date
            $completedOrders = Order::where('seller_id', $request->user()->id)
                ->where('status', 'completed')
                ->whereNotNull('completed_at')
                ->orderBy('completed_at', 'desc')
                ->with('listing')
                ->get();

            // Calculate order breakdown by matching withdrawal amount against completed orders
            // We'll allocate funds starting from most recent orders
            $orderBreakdown = [];
            $remainingAmount = $validated['amount'];
            
            foreach ($completedOrders as $order) {
                if ($remainingAmount <= 0) {
                    break;
                }
                
                // Check if this order hasn't been fully allocated to previous withdrawals
                // For simplicity, we'll use a FIFO approach: allocate from oldest to newest
                // But for display, we'll show newest first
                $allocatedAmount = min((float)$order->amount, $remainingAmount);
                
                if ($allocatedAmount > 0) {
                    $orderBreakdown[] = [
                        'order_id' => $order->id,
                        'order_number' => 'NXO-' . $order->id,
                        'amount' => round($allocatedAmount, 2),
                        'listing_title' => $order->listing->title ?? 'N/A',
                        'completed_at' => $order->completed_at ? $order->completed_at->toIso8601String() : null,
                    ];
                    $remainingAmount -= $allocatedAmount;
                }
            }
            
            // Reverse to show newest orders first
            $orderBreakdown = array_reverse($orderBreakdown);

            // Move funds from available_balance to on_hold_balance (hold until admin approval)
            // This ensures funds are reserved but not yet withdrawn
            $wallet->available_balance -= $validated['amount'];
            $wallet->on_hold_balance += $validated['amount'];
            $wallet->save();

            // Create withdrawal request record - status will be pending for admin approval
            $withdrawalRequest = WithdrawalRequest::create([
                'user_id' => $request->user()->id,
                'wallet_id' => $wallet->id,
                'amount' => $validated['amount'],
                'iban' => $validated['iban'],
                'bank_account' => $validated['iban'], // Legacy field for backward compatibility
                'bank_name' => $validated['bank_name'],
                'account_holder_name' => $validated['account_holder_name'],
                'order_breakdown' => $orderBreakdown, // Store order breakdown
                'status' => WithdrawalRequest::STATUS_PENDING, // Pending admin approval
            ]);

            // Audit log for withdrawal request creation
            AuditHelper::log(
                'wallet.withdraw.requested',
                WithdrawalRequest::class,
                $withdrawalRequest->id,
                null,
                [
                    'amount' => $validated['amount'],
                    'iban' => substr($validated['iban'], 0, 4) . '****', // Mask sensitive data
                    'bank_name' => $validated['bank_name'],
                    'account_holder_name' => $validated['account_holder_name'],
                    'status' => WithdrawalRequest::STATUS_PENDING,
                    'note' => 'Withdrawal request submitted - awaiting admin approval',
                ],
                $request
            );

            Log::info('Withdrawal request created - awaiting admin approval', [
                'withdrawal_request_id' => $withdrawalRequest->id,
                'user_id' => $request->user()->id,
                'amount' => $validated['amount'],
            ]);

            // Notify admin of new withdrawal request (optional - can be added later)
            // $adminUsers = User::where('role', 'admin')->get();
            // foreach ($adminUsers as $admin) {
            //     $admin->notify(new NewWithdrawalRequest($withdrawalRequest));
            // }

            return response()->json([
                'message' => 'تم إرسال طلب السحب بنجاح. سيتم مراجعته من قبل الإدارة قريباً.',
                'wallet' => $wallet->fresh(),
                'withdrawal_request' => $withdrawalRequest,
            ]);
        });
    }
}
