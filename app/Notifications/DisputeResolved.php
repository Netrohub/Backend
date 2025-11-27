<?php

namespace App\Notifications;

use App\Models\Dispute;
use App\Helpers\SecurityHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class DisputeResolved extends Notification implements ShouldQueue
{
    use Queueable;

    // Store essential data to handle deleted models
    public ?int $disputeId = null;
    public ?int $orderId = null;
    public ?int $buyerId = null;
    public ?string $status = null;
    public ?string $resolutionNotes = null;
    public string $resolution;

    public function __construct(
        public ?Dispute $dispute = null,
        string $resolution = ''
    ) {
        $this->resolution = $resolution;
        
        // Store essential data in case model is deleted
        if ($dispute) {
            $this->disputeId = $dispute->id;
            $this->orderId = $dispute->order_id;
            $this->status = $dispute->status;
            $this->resolutionNotes = $dispute->resolution_notes;
        }
    }

    /**
     * Get dispute and order data, handling deleted models
     */
    private function getData(): array
    {
        // Try to reload model if it exists
        if ($this->disputeId) {
            try {
                $this->dispute = Dispute::with('order')->findOrFail($this->disputeId);
                $this->orderId = $this->dispute->order_id;
                $this->status = $this->dispute->status;
                $this->resolutionNotes = $this->dispute->resolution_notes;
            } catch (ModelNotFoundException $e) {
                // Model was deleted, use stored data
            }
        }

        // Get buyer_id from order if available
        if ($this->dispute?->order) {
            $this->buyerId = $this->dispute->order->buyer_id;
        } elseif ($this->orderId) {
            try {
                $order = \App\Models\Order::find($this->orderId);
                $this->buyerId = $order?->buyer_id;
            } catch (\Exception $e) {
                // Order also deleted
            }
        }

        return [
            'dispute_id' => $this->dispute?->id ?? $this->disputeId ?? 0,
            'order_id' => $this->dispute?->order_id ?? $this->orderId ?? 0,
            'buyer_id' => $this->buyerId ?? 0,
            'status' => $this->dispute?->status ?? $this->status ?? 'unknown',
            'resolution_notes' => $this->dispute?->resolution_notes ?? $this->resolutionNotes,
        ];
    }

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
        $data = $this->getData();
        $isBuyer = $notifiable->id === $data['buyer_id'];
        
        $resolutionMessage = match($this->resolution) {
            'refund_buyer' => $isBuyer ? 'سيتم استرداد المبلغ' : 'سيتم استرداد المبلغ للمشتري',
            'release_to_seller' => $isBuyer ? 'تم تحرير الأموال للبائع' : 'تم تحرير الأموال إلى محفظتك',
            default => 'تم حل النزاع',
        };

        return [
            'type' => 'dispute',
            'title' => 'تم حل النزاع',
            'message' => "نزاع طلب #{$data['order_id']} - {$resolutionMessage}",
            'icon' => 'AlertTriangle',
            'color' => 'text-green-400',
            'data' => [
                'dispute_id' => $data['dispute_id'],
                'order_id' => $data['order_id'],
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
        $data = $this->getData();
        $isBuyer = $notifiable->id === $data['buyer_id'];
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
            ->subject("Dispute Resolved - Order #{$data['order_id']}")
            ->greeting("Hello {$userName},")
            ->line("The dispute for Order #{$data['order_id']} has been resolved.")
            ->line("Resolution:")
            ->line("- Status: " . ucfirst(str_replace('_', ' ', $data['status'])))
            ->line("- Decision: " . ucfirst(str_replace('_', ' ', $this->resolution)))
            ->line("- {$resolutionMessage}")
            ->when($data['resolution_notes'], function ($mail) use ($data) {
                return $mail->line("Notes: {$data['resolution_notes']}");
            })
            ->action('View Dispute', SecurityHelper::frontendUrl('/disputes/' . $data['dispute_id']))
            ->line('Thank you for your patience during the dispute resolution process.');
    }
}

