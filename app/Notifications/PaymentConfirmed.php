<?php

namespace App\Notifications;

use App\Models\Order;
use App\Helpers\SecurityHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Order $order
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
        $userName = $notifiable->username ?? $notifiable->name;
        
        return (new MailMessage)
            ->subject("Payment Confirmed - Order #{$this->order->id}")
            ->greeting("Hello {$userName},")
            ->line("Your payment of SAR " . number_format($this->order->amount, 2) . " for Order #{$this->order->id} has been confirmed.")
            ->line("The funds are now held in escrow and will be released to the seller after 12 hours if no dispute is filed.")
            ->line("Order Details:")
            ->line("- Order ID: #{$this->order->id}")
            ->line("- Amount: SAR " . number_format($this->order->amount, 2))
            ->line("- Payment Status: Confirmed")
            ->action('View Order', SecurityHelper::frontendUrl('/orders/' . $this->order->id))
            ->line('If you have any concerns, please contact support or file a dispute.')
            ->line('Thank you for using NXOLand!');
    }
}

