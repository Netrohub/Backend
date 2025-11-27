<?php

namespace App\Notifications;

use App\Models\Listing;
use App\Models\Order;
use App\Helpers\SecurityHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AccountSold extends Notification implements ShouldQueue
{
    use Queueable;

    // Store essential data to handle deleted models
    public ?int $listingId = null;
    public ?string $listingTitle = null;
    public ?int $orderId = null;
    public ?float $orderAmount = null;
    public ?int $buyerId = null;

    public function __construct(
        public ?Listing $listing = null,
        public ?Order $order = null
    ) {
        // Store essential data in case models are deleted
        if ($listing) {
            $this->listingId = $listing->id;
            $this->listingTitle = $listing->title;
        }
        if ($order) {
            $this->orderId = $order->id;
            $this->orderAmount = $order->amount;
            $this->buyerId = $order->buyer_id;
        }
    }

    /**
     * Get listing and order data, handling deleted models
     */
    private function getData(): array
    {
        // Try to reload models if they exist
        if ($this->listingId) {
            try {
                $this->listing = Listing::findOrFail($this->listingId);
                $this->listingTitle = $this->listing->title;
            } catch (ModelNotFoundException $e) {
                // Model was deleted, use stored data
            }
        }
        
        if ($this->orderId) {
            try {
                $this->order = Order::findOrFail($this->orderId);
                $this->orderAmount = $this->order->amount;
                $this->buyerId = $this->order->buyer_id;
            } catch (ModelNotFoundException $e) {
                // Model was deleted, use stored data
            }
        }

        return [
            'listing_title' => $this->listing?->title ?? $this->listingTitle ?? 'Your listing',
            'order_id' => $this->order?->id ?? $this->orderId ?? 0,
            'order_amount' => $this->order?->amount ?? $this->orderAmount ?? 0,
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
        $data = $this->getData();
        $userName = $notifiable->username ?? $notifiable->name;

        return (new MailMessage)
            ->subject("🎉 Your Account Has Been Sold! - Order #{$data['order_id']}")
            ->greeting("Hello {$userName},")
            ->line("Congratulations! Your account listing \"{$data['listing_title']}\" has been sold.")
            ->line("Sale Details:")
            ->line("- Order ID: #{$data['order_id']}")
            ->line("- Listing: {$data['listing_title']}")
            ->line("- Sale Amount: SAR " . number_format($data['order_amount'], 2))
            ->line("- Buyer: User #{$data['buyer_id']}")
            ->line("The funds from this sale are currently held in escrow and will be released to your wallet after 12 hours if no dispute is filed by the buyer.")
            ->line("You can track the order status and view your earnings in your dashboard.")
            ->action('View Order Details', SecurityHelper::frontendUrl('/orders/' . $data['order_id']))
            ->action('View My Listings', SecurityHelper::frontendUrl('/my-listings'))
            ->line("Thank you for using NXOLand!")
            ->line("If you have any questions, please don't hesitate to contact our support team.");
    }
}

