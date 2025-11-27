<?php

namespace App\Notifications;

use App\Models\Dispute;
use App\Helpers\SecurityHelper;
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
        return ['mail', 'database'];
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): array
    {
        $order = $this->dispute->order;
        $isBuyer = $notifiable->id === $order->buyer_id;
        
        $resolutionMessage = match($this->resolution) {
            'refund_buyer' => $isBuyer ? 'سيتم استرداد المبلغ' : 'سيتم استرداد المبلغ للمشتري',
            'release_to_seller' => $isBuyer ? 'تم تحرير الأموال للبائع' : 'تم تحرير الأموال إلى محفظتك',
            default => 'تم حل النزاع',
        };

        return [
            'type' => 'dispute',
            'title' => 'تم حل النزاع',
            'message' => "نزاع طلب #{$order->id} - {$resolutionMessage}",
            'icon' => 'AlertTriangle',
            'color' => 'text-green-400',
            'data' => [
                'dispute_id' => $this->dispute->id,
                'order_id' => $order->id,
                'status' => 'resolved',
                'resolution' => $this->resolution,
            ],
            'read' => false,
        ];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->dispute->order;
        $isBuyer = $notifiable->id === $order->buyer_id;
        $userName = $notifiable->username ?? $notifiable->name;
        
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
            ->greeting("Hello {$userName},")
            ->line("The dispute for Order #{$order->id} has been resolved.")
            ->line("Resolution:")
            ->line("- Status: " . ucfirst(str_replace('_', ' ', $this->dispute->status)))
            ->line("- Decision: " . ucfirst(str_replace('_', ' ', $this->resolution)))
            ->line("- {$resolutionMessage}")
            ->when($this->dispute->resolution_notes, function ($mail) {
                return $mail->line("Notes: {$this->dispute->resolution_notes}");
            })
            ->action('View Dispute', SecurityHelper::frontendUrl('/disputes/' . $this->dispute->id))
            ->line('Thank you for your patience during the dispute resolution process.');
    }
}

