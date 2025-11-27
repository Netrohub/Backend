<?php

namespace App\Notifications;

use App\Models\WithdrawalRequest;
use App\Helpers\SecurityHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WithdrawalApproved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public WithdrawalRequest $withdrawalRequest
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
            'title' => 'تم الموافقة على طلب السحب',
            'message' => "تم الموافقة على طلب السحب بمبلغ $" . number_format($this->withdrawalRequest->amount, 2) . " وسيتم تحويله خلال 1-4 أيام عمل",
            'icon' => 'CheckCircle2',
            'color' => 'text-green-400',
            'data' => [
                'withdrawal_request_id' => $this->withdrawalRequest->id,
                'amount' => $this->withdrawalRequest->amount,
                'status' => 'approved',
                'tap_transfer_id' => $this->withdrawalRequest->tap_transfer_id,
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
            ->subject("Withdrawal Approved - $" . number_format($this->withdrawalRequest->amount, 2))
            ->greeting("Hello " . ($notifiable->username ?? $notifiable->name) . ",")
            ->line("Your withdrawal request for $" . number_format($this->withdrawalRequest->amount, 2) . " has been approved.")
            ->line("The transfer has been initiated and should be completed within 1-4 business days.")
            ->line("Withdrawal Details:")
            ->line("- Request ID: #{$this->withdrawalRequest->id}")
            ->line("- Amount: $" . number_format($this->withdrawalRequest->amount, 2))
            ->line("- Status: Processing")
            ->line("- Bank: {$this->withdrawalRequest->bank_name}")
            ->action('View Withdrawal Status', SecurityHelper::frontendUrl('/wallet'))
            ->line('You will be notified once the transfer is completed.')
            ->line('Thank you for using NXOLand!');
    }
}

