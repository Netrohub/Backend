<?php

namespace App\Notifications;

use App\Models\KycVerification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class KycVerified extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public KycVerification $kycVerification,
        public bool $verified
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
        if ($this->verified) {
            return [
                'type' => 'kyc',
                'title' => 'تم التحقق من الهوية بنجاح',
                'message' => 'تم التحقق من هويتك بنجاح. يمكنك الآن إضافة إعلانات للبيع.',
                'icon' => 'ShieldCheck',
                'color' => 'text-green-400',
                'data' => [
                    'kyc_id' => $this->kycVerification->id,
                    'status' => 'verified',
                    'inquiry_id' => $this->kycVerification->persona_inquiry_id,
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
                    'kyc_id' => $this->kycVerification->id,
                    'status' => $this->kycVerification->status,
                    'inquiry_id' => $this->kycVerification->persona_inquiry_id,
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
        if ($this->verified) {
            return (new MailMessage)
                ->subject('تم التحقق من الهوية بنجاح - NXOLand')
                ->greeting("مرحباً {$notifiable->name},")
                ->line('تم التحقق من هويتك بنجاح عبر Persona.')
                ->line('يمكنك الآن:')
                ->line('• إضافة إعلانات للبيع')
                ->line('• استخدام جميع ميزات المنصة')
                ->line('• إتمام المعاملات بأمان')
                ->action('عرض الملف الشخصي', url('/profile'))
                ->line('شكراً لاستخدامك NXOLand!');
        } else {
            return (new MailMessage)
                ->subject('فشل التحقق من الهوية - NXOLand')
                ->greeting("مرحباً {$notifiable->name},")
                ->line('لم يتم التحقق من هويتك بنجاح.')
                ->line('الحالة: ' . $this->kycVerification->status)
                ->line('يرجى المحاولة مرة أخرى أو الاتصال بالدعم إذا استمرت المشكلة.')
                ->action('إعادة المحاولة', url('/kyc'))
                ->line('إذا كنت بحاجة إلى مساعدة، يرجى الاتصال بالدعم.');
        }
    }
}

