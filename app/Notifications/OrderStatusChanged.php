<?php

namespace App\Notifications;

use App\Models\Order;
use App\Helpers\SecurityHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class OrderStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    // Store essential data to handle deleted models
    public ?int $orderId = null;
    public ?float $amount = null;
    public ?int $buyerId = null;
    public string $oldStatus;
    public string $newStatus;

    public function __construct(
        public ?Order $order = null,
        string $oldStatus = '',
        string $newStatus = ''
    ) {
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
        
        // Store essential data in case model is deleted
        if ($order) {
            $this->orderId = $order->id;
            $this->amount = $order->amount;
            $this->buyerId = $order->buyer_id;
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
            'buyer_id' => $this->order?->buyer_id ?? $this->buyerId ?? 0,
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
        $orderData = $this->getOrderData();
        
        $statusMessages = [
            'pending' => 'is pending payment',
            'paid' => 'payment has been received',
            'escrow_hold' => 'payment has been received and is being held in escrow',
            'completed' => 'has been completed and funds have been released',
            'cancelled' => 'has been cancelled',
            'disputed' => 'has a dispute filed',
        ];

        $message = $statusMessages[$this->newStatus] ?? 'status has changed';
        $isBuyer = $notifiable->id === $orderData['buyer_id'];
        $role = $isBuyer ? 'buyer' : 'seller';
        $userName = $notifiable->username ?? $notifiable->name;

        return (new MailMessage)
            ->subject("Order #{$orderData['id']} {$message}")
            ->greeting("Hello {$userName},")
            ->line("Your order #{$orderData['id']} as {$role} {$message}.")
            ->line("Order Details:")
            ->line("- Order ID: #{$orderData['id']}")
            ->line("- Amount: $" . number_format($orderData['amount'], 2) . " USD")
            ->line("- Status: " . ucfirst(str_replace('_', ' ', $this->newStatus)))
            ->when($this->newStatus === 'escrow_hold', function ($mail) {
                return $mail->line('Your payment is secure and will be released to the seller after 12 hours if no dispute is filed.');
            })
            ->when($this->newStatus === 'completed', function ($mail) use ($isBuyer) {
                return $mail->line($isBuyer 
                    ? 'Thank you for your purchase!' 
                    : 'Funds have been released to your wallet.');
            })
            ->action('View Order', SecurityHelper::frontendUrl('/orders/' . $orderData['id']))
            ->line('Thank you for using NXOLand!');
    }
}

