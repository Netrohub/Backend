<?php

namespace App\Notifications;

use App\Models\KycVerification;
use App\Helpers\SecurityHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class KycVerified extends Notification implements ShouldQueue
{
    use Queueable;

    // Store essential data to handle deleted models
    public ?int $kycId = null;
    public ?string $kycStatus = null;
    public ?string $personaInquiryId = null;
    public bool $verified;

    public function __construct(
        public ?KycVerification $kycVerification = null,
        bool $verified = false
    ) {
        $this->verified = $verified;
        
        // Store essential data in case model is deleted
        if ($kycVerification) {
            $this->kycId = $kycVerification->id;
            $this->kycStatus = $kycVerification->status;
            $this->personaInquiryId = $kycVerification->persona_inquiry_id;
        }
    }

    /**
     * Get KYC data, handling deleted models
     */
    private function getKycData(): array
    {
        // Try to reload model if it exists
        if ($this->kycId) {
            try {
                $this->kycVerification = KycVerification::findOrFail($this->kycId);
                $this->kycStatus = $this->kycVerification->status;
                $this->personaInquiryId = $this->kycVerification->persona_inquiry_id;
            } catch (ModelNotFoundException $e) {
                // Model was deleted, use stored data
            }
        }

        return [
            'id' => $this->kycVerification?->id ?? $this->kycId ?? 0,
            'status' => $this->kycVerification?->status ?? $this->kycStatus ?? 'unknown',
            'persona_inquiry_id' => $this->kycVerification?->persona_inquiry_id ?? $this->personaInquiryId,
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
        $kycData = $this->getKycData();
        
        if ($this->verified) {
            return [
                'type' => 'kyc',
                'title' => 'تم التحقق من الهوية بنجاح',
                'message' => 'تم التحقق من هويتك بنجاح. يمكنك الآن إضافة إعلانات للبيع.',
                'icon' => 'ShieldCheck',
                'color' => 'text-green-400',
                'data' => [
                    'kyc_id' => $kycData['id'],
                    'status' => 'verified',
                    'inquiry_id' => $kycData['persona_inquiry_id'],
                ],
                'read' => false,
            ];
        } else {
            return [
                'type' => 'kyc',
                'title' => 'فشل التحقق من الهوية',
                'message' => 'لم يتم التحقق من هويتك. يرجى المحاولة مرة أخرى.',
                'icon' => 'AlertCircle',
                'color' => 'text-red-400',
                'data' => [
                    'kyc_id' => $kycData['id'],
                    'status' => $kycData['status'],
                    'inquiry_id' => $kycData['persona_inquiry_id'],
                ],
                'read' => false,
            ];
        }
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $kycData = $this->getKycData();
        
        if ($this->verified) {
            return (new MailMessage)
                ->subject('تم التحقق من الهوية بنجاح - NXOLand')
                ->greeting("مرحباً " . ($notifiable->username ?? $notifiable->name) . ",")
                ->line('تم التحقق من هويتك بنجاح عبر Persona.')
                ->line('يمكنك الآن:')
                ->line('• إضافة إعلانات للبيع')
                ->line('• استخدام جميع ميزات المنصة')
                ->line('• إتمام المعاملات بأمان')
                ->action('عرض الملف الشخصي', SecurityHelper::frontendUrl('/profile'))
                ->line('شكراً لاستخدامك NXOLand!');
        } else {
            return (new MailMessage)
                ->subject('فشل التحقق من الهوية - NXOLand')
                ->greeting("مرحباً " . ($notifiable->username ?? $notifiable->name) . ",")
                ->line('لم يتم التحقق من هويتك بنجاح.')
                ->line('الحالة: ' . $kycData['status'])
                ->line('يرجى المحاولة مرة أخرى أو الاتصال بالدعم إذا استمرت المشكلة.')
                ->action('إعادة المحاولة', SecurityHelper::frontendUrl('/kyc'))
                ->line('إذا كنت بحاجة إلى مساعدة، يرجى الاتصال بالدعم.');
        }
    }
}

