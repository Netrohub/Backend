<?php

namespace App\Notifications;

use App\Models\Order;
use App\Helpers\SecurityHelper;
use App\Helpers\EmailHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PaymentConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    // Store essential data to handle deleted models
    public ?int $orderId = null;
    public ?float $amount = null;

    public function __construct(
        public ?Order $order = null
    ) {
        // Store essential data in case model is deleted
        if ($order) {
            $this->orderId = $order->id;
            $this->amount = $order->amount;
        }
    }

    /**
     * Get order data, handling deleted models
     */
    private function getOrderData(): array
    {
        // Try to reload model if it exists
        if ($this->orderId) {
            try {
                $this->order = Order::findOrFail($this->orderId);
            } catch (ModelNotFoundException $e) {
                // Model was deleted, use stored data
            }
        }

        // Use model if available, otherwise use stored data
        return [
            'id' => $this->order?->id ?? $this->orderId ?? 0,
            'amount' => $this->order?->amount ?? $this->amount ?? 0,
        ];
    }

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
        $orderData = $this->getOrderData();
        
        return (new MailMessage)
            ->subject("Payment Confirmed - Order #{$orderData['id']}")
            ->greeting("Hello {$userName},")
            ->line("Your payment of $" . number_format($orderData['amount'], 2) . " USD for Order #{$orderData['id']} has been confirmed.")
            ->line("The funds are now held in escrow and will be released to the seller after 12 hours if no dispute is filed.")
            ->line("Order Details:")
            ->line("- Order ID: #{$orderData['id']}")
            ->line("- Amount: $" . number_format($orderData['amount'], 2) . " USD")
            ->line("- Payment Status: Confirmed")
            ->action('View Order', SecurityHelper::frontendUrl('/orders/' . $orderData['id']))
            ->line('If you have any concerns, please contact support or file a dispute.')
            ->line('Thank you for using NXOLand!');
    }
}

