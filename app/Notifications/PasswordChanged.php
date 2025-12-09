<?php

namespace App\Notifications;

use App\Helpers\SecurityHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordChanged extends Notification implements ShouldQueue
{
    use Queueable;

    protected $time;
    protected $ipAddress;
    protected $userAgent;

    /**
     * Create a new notification instance.
     */
    public function __construct($time, $ipAddress, $userAgent)
    {
        $this->time = $time;
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('تم تغيير كلمة المرور - NXOLand')
            ->greeting('مرحباً ' . ($notifiable->username ?? $notifiable->name) . '!')
            ->line('تم تغيير كلمة مرور حسابك بنجاح.')
            ->line('**تفاصيل التغيير:**')
            ->line('⏰ الوقت: ' . $this->time)
            ->line('🌐 عنوان IP: ' . $this->ipAddress)
            ->line('💻 المتصفح: ' . $this->parseUserAgent($this->userAgent))
            ->line('')
            ->line('**إجراءات أمنية مهمة:**')
            ->line('• تم تسجيل الخروج تلقائياً من جميع الأجهزة الأخرى')
            ->line('• ستحتاج إلى تسجيل الدخول مرة أخرى على الأجهزة الأخرى')
            ->line('')
            ->line('⚠️ **إذا لم تقم بهذا التغيير:**')
            ->line('يرجى الاتصال بفريق الدعم فوراً على support@nxoland.com')
            ->action('تسجيل الدخول إلى حسابك', SecurityHelper::frontendUrl('/auth'))
            ->line('شكراً لاستخدام منصة NXOLand!')
            ->salutation('مع أطيب التحيات، فريق NXOLand');
    }

    /**
     * Parse user agent to extract browser and platform info
     */
    private function parseUserAgent($userAgent): string
    {
        // Simple user agent parsing
        if (str_contains($userAgent, 'Chrome')) {
            $browser = 'Chrome';
        } elseif (str_contains($userAgent, 'Firefox')) {
            $browser = 'Firefox';
        } elseif (str_contains($userAgent, 'Safari')) {
            $browser = 'Safari';
        } elseif (str_contains($userAgent, 'Edge')) {
            $browser = 'Edge';
        } else {
            $browser = 'Unknown';
        }

        if (str_contains($userAgent, 'Windows')) {
            $platform = 'Windows';
        } elseif (str_contains($userAgent, 'Mac')) {
            $platform = 'macOS';
        } elseif (str_contains($userAgent, 'Linux')) {
            $platform = 'Linux';
        } elseif (str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad')) {
            $platform = 'iOS';
        } elseif (str_contains($userAgent, 'Android')) {
            $platform = 'Android';
        } else {
            $platform = 'Unknown';
        }

        return "{$browser} على {$platform}";
    }
}

