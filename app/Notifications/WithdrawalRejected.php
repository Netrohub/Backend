<?php

namespace App\Notifications;

use App\Models\WithdrawalRequest;
use App\Helpers\SecurityHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class WithdrawalRejected extends Notification implements ShouldQueue
{
    use Queueable;

    // Store essential data to handle deleted models
    public ?int $withdrawalRequestId = null;
    public ?float $amount = null;
    public string $reason;

    public function __construct(
        public ?WithdrawalRequest $withdrawalRequest = null,
        string $reason = ''
    ) {
        $this->reason = $reason;
        
        // Store essential data in case model is deleted
        if ($withdrawalRequest) {
            $this->withdrawalRequestId = $withdrawalRequest->id;
            $this->amount = $withdrawalRequest->amount;
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
            'title' => 'تم رفض طلب السحب',
            'message' => "تم رفض طلب السحب بمبلغ $" . number_format($data['amount'], 2) . ". السبب: {$this->reason}",
            'icon' => 'XCircle',
            'color' => 'text-red-400',
            'data' => [
                'withdrawal_request_id' => $data['id'],
                'amount' => $data['amount'],
                'reason' => $this->reason,
                'status' => 'rejected',
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
            ->subject("Withdrawal Request Rejected - $" . number_format($data['amount'], 2))
            ->greeting("Hello " . ($notifiable->username ?? $notifiable->name) . ",")
            ->line("Your withdrawal request for $" . number_format($data['amount'], 2) . " has been rejected.")
            ->line("Reason: {$this->reason}")
            ->line("The amount has been refunded to your available balance.")
            ->line("Withdrawal Details:")
            ->line("- Request ID: #{$data['id']}")
            ->line("- Amount: $" . number_format($data['amount'], 2))
            ->line("- Status: Rejected")
            ->action('View Wallet', SecurityHelper::frontendUrl('/wallet'))
            ->line('If you have any questions, please contact support.')
            ->line('Thank you for using NXOLand!');
    }
}

