<?php

namespace App\Notifications;

use App\Models\Listing;
use App\Models\Order;
use App\Helpers\SecurityHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountSold extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Listing $listing,
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
        $listingTitle = $this->listing->title;
        $orderId = $this->order->id;
        $amount = number_format($this->order->amount, 2);
        $userName = $notifiable->username ?? $notifiable->name;

        return (new MailMessage)
            ->subject("🎉 Your Account Has Been Sold! - Order #{$orderId}")
            ->greeting("Hello {$userName},")
            ->line("Congratulations! Your account listing \"{$listingTitle}\" has been sold.")
            ->line("Sale Details:")
            ->line("- Order ID: #{$orderId}")
            ->line("- Listing: {$listingTitle}")
            ->line("- Sale Amount: SAR {$amount}")
            ->line("- Buyer: User #{$this->order->buyer_id}")
            ->line("The funds from this sale are currently held in escrow and will be released to your wallet after 12 hours if no dispute is filed by the buyer.")
            ->line("You can track the order status and view your earnings in your dashboard.")
            ->action('View Order Details', SecurityHelper::frontendUrl('/orders/' . $orderId))
            ->action('View My Listings', SecurityHelper::frontendUrl('/my-listings'))
            ->line("Thank you for using NXOLand!")
            ->line("If you have any questions, please don't hesitate to contact our support team.");
    }
}

