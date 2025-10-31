<?php

namespace App\Notifications;

use App\Models\Dispute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DisputeCreated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Dispute $dispute
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
        $isInitiator = $notifiable->id === $this->dispute->initiated_by;
        
        if ($isInitiator) {
            return (new MailMessage)
                ->subject("Dispute Filed - Order #{$order->id}")
                ->greeting("Hello {$notifiable->name},")
                ->line("Your dispute for Order #{$order->id} has been filed successfully.")
                ->line("Dispute Details:")
                ->line("- Order ID: #{$order->id}")
                ->line("- Reason: " . ucfirst(str_replace('_', ' ', $this->dispute->reason)))
                ->line("- Description: {$this->dispute->description}")
                ->line("- Status: Under Review")
                ->line("Our team will review your dispute and respond within 24-48 hours.")
                ->action('View Dispute', url('/disputes/' . $this->dispute->id))
                ->line('Thank you for your patience.');
        } else {
            return (new MailMessage)
                ->subject("Dispute Filed Against Order #{$order->id}")
                ->greeting("Hello {$notifiable->name},")
                ->line("A dispute has been filed for Order #{$order->id}.")
                ->line("Dispute Details:")
                ->line("- Order ID: #{$order->id}")
                ->line("- Reason: " . ucfirst(str_replace('_', ' ', $this->dispute->reason)))
                ->line("- Description: {$this->dispute->description}")
                ->line("- Status: Under Review")
                ->line("Our team will review the dispute and respond within 24-48 hours.")
                ->line("Funds for this order are currently on hold until the dispute is resolved.")
                ->action('View Dispute', url('/disputes/' . $this->dispute->id))
                ->line('If you have any questions, please contact support.');
        }
    }
}

