<?php

namespace App\Notifications;

use App\Models\Dispute;
use App\Helpers\SecurityHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class DisputeCreated extends Notification implements ShouldQueue
{
    use Queueable;

    // Store essential data to handle deleted models
    public ?int $disputeId = null;
    public ?int $orderId = null;
    public ?int $initiatedBy = null;
    public ?string $reason = null;
    public ?string $description = null;
    public ?string $status = null;

    public function __construct(
        public ?Dispute $dispute = null
    ) {
        // Store essential data in case model is deleted
        if ($dispute) {
            $this->disputeId = $dispute->id;
            $this->orderId = $dispute->order_id;
            $this->initiatedBy = $dispute->initiated_by;
            $this->reason = $dispute->reason;
            $this->description = $dispute->description;
            $this->status = $dispute->status;
        }
    }

    /**
     * Get dispute data, handling deleted models
     */
    private function getDisputeData(): array
    {
        // Try to reload model if it exists
        if ($this->disputeId) {
            try {
                $this->dispute = Dispute::with('order')->findOrFail($this->disputeId);
                $this->orderId = $this->dispute->order_id;
                $this->initiatedBy = $this->dispute->initiated_by;
                $this->reason = $this->dispute->reason;
                $this->description = $this->dispute->description;
                $this->status = $this->dispute->status;
            } catch (ModelNotFoundException $e) {
                // Model was deleted, use stored data
            }
        }

        return [
            'id' => $this->dispute?->id ?? $this->disputeId ?? 0,
            'order_id' => $this->dispute?->order_id ?? $this->orderId ?? 0,
            'initiated_by' => $this->dispute?->initiated_by ?? $this->initiatedBy ?? 0,
            'reason' => $this->dispute?->reason ?? $this->reason ?? 'unknown',
            'description' => $this->dispute?->description ?? $this->description ?? '',
            'status' => $this->dispute?->status ?? $this->status ?? 'unknown',
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
        $data = $this->getDisputeData();
        $isInitiator = $notifiable->id === $data['initiated_by'];

        return [
            'type' => 'dispute',
            'title' => $isInitiator ? 'تم فتح النزاع' : 'تم فتح نزاع على طلبك',
            'message' => "نزاع على طلب #{$data['order_id']} - {$data['reason']}",
            'icon' => 'AlertTriangle',
            'color' => 'text-yellow-400',
            'data' => [
                'dispute_id' => $data['id'],
                'order_id' => $data['order_id'],
                'status' => $data['status'],
            ],
            'read' => false,
        ];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->getDisputeData();
        $isInitiator = $notifiable->id === $data['initiated_by'];
        $userName = $notifiable->username ?? $notifiable->name;
        
        if ($isInitiator) {
            return (new MailMessage)
                ->subject("Dispute Filed - Order #{$data['order_id']}")
                ->greeting("Hello {$userName},")
                ->line("Your dispute for Order #{$data['order_id']} has been filed successfully.")
                ->line("Dispute Details:")
                ->line("- Order ID: #{$data['order_id']}")
                ->line("- Reason: " . ucfirst(str_replace('_', ' ', $data['reason'])))
                ->line("- Description: {$data['description']}")
                ->line("- Status: Under Review")
                ->line("Our team will review your dispute and respond within 24-48 hours.")
                ->action('View Dispute', SecurityHelper::frontendUrl('/disputes/' . $data['id']))
                ->line('Thank you for your patience.');
        } else {
            return (new MailMessage)
                ->subject("Dispute Filed Against Order #{$data['order_id']}")
                ->greeting("Hello {$userName},")
                ->line("A dispute has been filed for Order #{$data['order_id']}.")
                ->line("Dispute Details:")
                ->line("- Order ID: #{$data['order_id']}")
                ->line("- Reason: " . ucfirst(str_replace('_', ' ', $data['reason'])))
                ->line("- Description: {$data['description']}")
                ->line("- Status: Under Review")
                ->line("Our team will review the dispute and respond within 24-48 hours.")
                ->line("Funds for this order are currently on hold until the dispute is resolved.")
                ->action('View Dispute', SecurityHelper::frontendUrl('/disputes/' . $data['id']))
                ->line('If you have any questions, please contact support.');
        }
    }
}

