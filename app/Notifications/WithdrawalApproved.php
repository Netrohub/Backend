<?php

namespace App\Notifications;

use App\Models\WithdrawalRequest;
use App\Helpers\SecurityHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class WithdrawalApproved extends Notification implements ShouldQueue
{
    use Queueable;

    // Store essential data to handle deleted models
    public ?int $withdrawalRequestId = null;
    public ?float $amount = null;
    public ?string $bankName = null;
    public ?string $tapTransferId = null;

    public function __construct(
        public ?WithdrawalRequest $withdrawalRequest = null
    ) {
        // Store essential data in case model is deleted
        if ($withdrawalRequest) {
            $this->withdrawalRequestId = $withdrawalRequest->id;
            $this->amount = $withdrawalRequest->amount;
            $this->bankName = $withdrawalRequest->bank_name;
            $this->tapTransferId = $withdrawalRequest->tap_transfer_id;
        }
    }

    /**
     * Get withdrawal request data, handling deleted models
     */
    private function getWithdrawalData(): array
    {
        // Try to reload model if it exists
        if ($this->withdrawalRequestId) {
            try {
                $this->withdrawalRequest = WithdrawalRequest::findOrFail($this->withdrawalRequestId);
            } catch (ModelNotFoundException $e) {
                // Model was deleted, use stored data
            }
        }

        // Use model if available, otherwise use stored data
        return [
            'id' => $this->withdrawalRequest?->id ?? $this->withdrawalRequestId ?? 0,
            'amount' => $this->withdrawalRequest?->amount ?? $this->amount ?? 0,
            'bank_name' => $this->withdrawalRequest?->bank_name ?? $this->bankName ?? 'N/A',
            'tap_transfer_id' => $this->withdrawalRequest?->tap_transfer_id ?? $this->tapTransferId,
        ];
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): array
    {
        $data = $this->getWithdrawalData();
        
        return [
            'type' => 'withdrawal',
            'title' => 'تم الموافقة على طلب السحب',
            'message' => "تم الموافقة على طلب السحب بمبلغ $" . number_format($data['amount'], 2) . " وسيتم تحويله خلال 1-4 أيام عمل",
            'icon' => 'CheckCircle2',
            'color' => 'text-green-400',
            'data' => [
                'withdrawal_request_id' => $data['id'],
                'amount' => $data['amount'],
                'status' => 'approved',
                'tap_transfer_id' => $data['tap_transfer_id'],
            ],
            'read' => false,
        ];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->getWithdrawalData();
        
        return (new MailMessage)
            ->subject("Withdrawal Approved - $" . number_format($data['amount'], 2))
            ->greeting("Hello " . ($notifiable->username ?? $notifiable->name) . ",")
            ->line("Your withdrawal request for $" . number_format($data['amount'], 2) . " has been approved.")
            ->line("The transfer has been initiated and should be completed within 1-4 business days.")
            ->line("Withdrawal Details:")
            ->line("- Request ID: #{$data['id']}")
            ->line("- Amount: $" . number_format($data['amount'], 2))
            ->line("- Status: Processing")
            ->line("- Bank: {$data['bank_name']}")
            ->action('View Withdrawal Status', SecurityHelper::frontendUrl('/wallet'))
            ->line('You will be notified once the transfer is completed.')
            ->line('Thank you for using NXOLand!');
    }
}

