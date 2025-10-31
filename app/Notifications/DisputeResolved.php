<?php

namespace App\Notifications;

use App\Models\Dispute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DisputeResolved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Dispute $dispute,
        public string $resolution
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
        $order = $this->dispute->order;
        $isBuyer = $notifiable->id === $order->buyer_id;
        
        $resolutionMessage = match($this->resolution) {
            'refund_buyer' => $isBuyer 
                ? 'You will receive a full refund for this order.'
                : 'The buyer will receive a refund for this order.',
            'release_to_seller' => $isBuyer
                ? 'Funds have been released to the seller.'
                : 'Funds have been released to your wallet.',
            default => 'The dispute has been resolved.',
        };

        return (new MailMessage)
            ->subject("Dispute Resolved - Order #{$order->id}")
            ->greeting("Hello {$notifiable->name},")
            ->line("The dispute for Order #{$order->id} has been resolved.")
            ->line("Resolution:")
            ->line("- Status: " . ucfirst(str_replace('_', ' ', $this->dispute->status)))
            ->line("- Decision: " . ucfirst(str_replace('_', ' ', $this->resolution)))
            ->line("- {$resolutionMessage}")
            ->when($this->dispute->resolution_notes, function ($mail) {
                return $mail->line("Notes: {$this->dispute->resolution_notes}");
            })
            ->action('View Dispute', url('/disputes/' . $this->dispute->id))
            ->line('Thank you for your patience during the dispute resolution process.');
    }
}

