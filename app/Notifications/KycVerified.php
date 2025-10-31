<?php

namespace App\Notifications;

use App\Models\KycVerification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class KycVerified extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public KycVerification $kyc,
        public bool $isVerified
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        if ($this->isVerified) {
            return (new MailMessage)
                ->subject("KYC Verification Approved")
                ->greeting("Hello {$notifiable->name},")
                ->line("Congratulations! Your KYC verification has been approved.")
                ->line("Your account is now verified, and you can:")
                ->line("- Create listings")
                ->line("- Make purchases")
                ->line("- Withdraw funds")
                ->line("- Access all platform features")
                ->action('Go to Dashboard', url('/dashboard'))
                ->line('Thank you for completing the verification process!');
        } else {
            return (new MailMessage)
                ->subject("KYC Verification Status Update")
                ->greeting("Hello {$notifiable->name},")
                ->line("Your KYC verification status has been updated.")
                ->line("Status: " . ucfirst($this->kyc->status))
                ->when($this->kyc->status === 'failed', function ($mail) {
                    return $mail
                        ->line("Unfortunately, your verification could not be completed.")
                        ->line("Please review your submitted information and try again.")
                        ->action('Retry Verification', url('/kyc'));
                })
                ->when($this->kyc->status === 'expired', function ($mail) {
                    return $mail
                        ->line("Your verification session has expired.")
                        ->line("Please submit a new verification request.")
                        ->action('Start Verification', url('/kyc'));
                })
                ->line('If you have any questions, please contact support.');
        }
    }
}

