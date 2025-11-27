<?php

namespace App\Notifications;

use App\Models\WithdrawalRequest;
use App\Helpers\SecurityHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WithdrawalRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public WithdrawalRequest $withdrawalRequest,
        public string $reason
    ) {}

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
        return [
            'type' => 'withdrawal',
            'title' => 'تم رفض طلب السحب',
            'message' => "تم رفض طلب السحب بمبلغ $" . number_format($this->withdrawalRequest->amount, 2) . ". السبب: {$this->reason}",
            'icon' => 'XCircle',
            'color' => 'text-red-400',
            'data' => [
                'withdrawal_request_id' => $this->withdrawalRequest->id,
                'amount' => $this->withdrawalRequest->amount,
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
        return (new MailMessage)
            ->subject("Withdrawal Request Rejected - $" . number_format($this->withdrawalRequest->amount, 2))
            ->greeting("Hello " . ($notifiable->username ?? $notifiable->name) . ",")
            ->line("Your withdrawal request for $" . number_format($this->withdrawalRequest->amount, 2) . " has been rejected.")
            ->line("Reason: {$this->reason}")
            ->line("The amount has been refunded to your available balance.")
            ->line("Withdrawal Details:")
            ->line("- Request ID: #{$this->withdrawalRequest->id}")
            ->line("- Amount: $" . number_format($this->withdrawalRequest->amount, 2))
            ->line("- Status: Rejected")
            ->action('View Wallet', SecurityHelper::frontendUrl('/wallet'))
            ->line('If you have any questions, please contact support.')
            ->line('Thank you for using NXOLand!');
    }
}

