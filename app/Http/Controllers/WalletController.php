<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use App\Services\TapPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\MessageHelper;
use App\Helpers\AuditHelper;

class WalletController extends Controller
{
    public function __construct(
        private TapPaymentService $tapService
    ) {}
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
        return DB::transaction(function () use ($wallet, $validated, $request) {
            // Reload wallet with lock to get fresh balance
            $wallet = Wallet::lockForUpdate()
                ->where('user_id', $wallet->user_id)
                ->firstOrFail();

            if ($wallet->available_balance < $validated['amount']) {
                return response()->json(['message' => MessageHelper::WALLET_INSUFFICIENT_BALANCE], 400);
            }

            // Deduct from available balance
            $wallet->available_balance -= $validated['amount'];
            $wallet->withdrawn_total += $validated['amount'];
            $wallet->save();

            // Create withdrawal request record
            $withdrawalRequest = WithdrawalRequest::create([
                'user_id' => $request->user()->id,
                'wallet_id' => $wallet->id,
                'amount' => $validated['amount'],
                'bank_account' => $validated['bank_account'],
                'status' => WithdrawalRequest::STATUS_PENDING,
            ]);

            // Initiate transfer via Tap Payments API
            try {
                $transferData = [
                    'amount' => $validated['amount'],
                    'currency' => 'SAR',
                    'destination' => [
                        'type' => 'bank_account',
                        'bank_account' => $validated['bank_account'],
                    ],
                    'metadata' => [
                        'withdrawal_request_id' => $withdrawalRequest->id,
                        'user_id' => $request->user()->id,
                    ],
                ];

                $tapResponse = $this->tapService->createTransfer($transferData);

                if (isset($tapResponse['id'])) {
                    // Update withdrawal request with Tap transfer ID
                    $withdrawalRequest->tap_transfer_id = $tapResponse['id'];
                    $withdrawalRequest->status = WithdrawalRequest::STATUS_PROCESSING;
                    $withdrawalRequest->tap_response = $tapResponse;
                    $withdrawalRequest->save();

                    Log::info('Withdrawal transfer initiated', [
                        'withdrawal_request_id' => $withdrawalRequest->id,
                        'tap_transfer_id' => $tapResponse['id'],
                        'amount' => $validated['amount'],
                    ]);

                    // Audit log for withdrawal
                    AuditHelper::log(
                        'wallet.withdraw',
                        WithdrawalRequest::class,
                        $withdrawalRequest->id,
                        null,
                        [
                            'amount' => $validated['amount'],
                            'bank_account' => substr($validated['bank_account'], 0, 4) . '****', // Mask sensitive data
                            'status' => WithdrawalRequest::STATUS_PROCESSING,
                            'tap_transfer_id' => $tapResponse['id'],
                        ],
                        $request
                    );
                } else {
                    // If Tap API call fails, mark as failed and refund wallet
                    $withdrawalRequest->status = WithdrawalRequest::STATUS_FAILED;
                    $withdrawalRequest->failure_reason = 'Failed to create transfer with payment gateway';
                    $withdrawalRequest->tap_response = $tapResponse;
                    $withdrawalRequest->save();

                    // Refund the wallet balance
                    $wallet->available_balance += $validated['amount'];
                    $wallet->withdrawn_total -= $validated['amount'];
                    $wallet->save();

                    Log::error('Withdrawal transfer failed', [
                        'withdrawal_request_id' => $withdrawalRequest->id,
                        'tap_response' => $tapResponse,
                    ]);

                    return response()->json([
                        'message' => 'Failed to initiate withdrawal. Please try again or contact support.',
                        'withdrawal_request' => $withdrawalRequest,
                    ], 500);
                }
            } catch (\Exception $e) {
                // If exception occurs, mark as failed and refund wallet
                $withdrawalRequest->status = WithdrawalRequest::STATUS_FAILED;
                $withdrawalRequest->failure_reason = 'Exception: ' . $e->getMessage();
                $withdrawalRequest->save();

                // Refund the wallet balance
                $wallet->available_balance += $validated['amount'];
                $wallet->withdrawn_total -= $validated['amount'];
                $wallet->save();

                // Determine error code and user-friendly message
                $errorCode = 'WITHDRAWAL_FAILED';
                $userMessage = 'An error occurred while processing withdrawal. Your balance has been refunded.';
                
                if (str_contains($e->getMessage(), 'network') || str_contains($e->getMessage(), 'timeout')) {
                    $errorCode = 'WITHDRAWAL_NETWORK_ERROR';
                    $userMessage = 'Unable to connect to payment gateway. Your balance has been refunded. Please try again later.';
                } elseif (str_contains($e->getMessage(), 'invalid') || str_contains($e->getMessage(), 'bank_account')) {
                    $errorCode = 'WITHDRAWAL_INVALID_ACCOUNT';
                    $userMessage = 'Invalid bank account information. Your balance has been refunded. Please check your account details.';
                }

                Log::error('Withdrawal transfer exception', [
                    'withdrawal_request_id' => $withdrawalRequest->id,
                    'error' => $e->getMessage(),
                    'error_code' => $errorCode,
                    'trace' => config('app.debug') ? $e->getTraceAsString() : null,
                ]);

                return response()->json([
                    'message' => $userMessage,
                    'error_code' => $errorCode,
                    'withdrawal_request' => $withdrawalRequest,
                    'error' => config('app.debug') ? $e->getMessage() : null,
                ], 500);
            }

            return response()->json([
                'message' => MessageHelper::WALLET_WITHDRAWAL_SUBMITTED,
                'wallet' => $wallet->fresh(),
                'withdrawal_request' => $withdrawalRequest,
            ]);
        });
    }
}
