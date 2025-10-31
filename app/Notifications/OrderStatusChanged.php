<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Order $order,
        public string $oldStatus,
        public string $newStatus
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
        $statusMessages = [
            'pending' => 'is pending payment',
            'paid' => 'payment has been received',
            'escrow_hold' => 'payment has been received and is being held in escrow',
            'completed' => 'has been completed and funds have been released',
            'cancelled' => 'has been cancelled',
            'disputed' => 'has a dispute filed',
        ];

        $message = $statusMessages[$this->newStatus] ?? 'status has changed';
        $isBuyer = $notifiable->id === $this->order->buyer_id;
        $role = $isBuyer ? 'buyer' : 'seller';

        return (new MailMessage)
            ->subject("Order #{$this->order->id} {$message}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your order #{$this->order->id} as {$role} {$message}.")
            ->line("Order Details:")
            ->line("- Order ID: #{$this->order->id}")
            ->line("- Amount: SAR " . number_format($this->order->amount, 2))
            ->line("- Status: " . ucfirst(str_replace('_', ' ', $this->newStatus)))
            ->when($this->newStatus === 'escrow_hold', function ($mail) {
                return $mail->line('Your payment is secure and will be released to the seller after 12 hours if no dispute is filed.');
            })
            ->when($this->newStatus === 'completed', function ($mail) use ($isBuyer) {
                return $mail->line($isBuyer 
                    ? 'Thank you for your purchase!' 
                    : 'Funds have been released to your wallet.');
            })
            ->action('View Order', url('/orders/' . $this->order->id))
            ->line('Thank you for using NXOLand!');
    }
}

