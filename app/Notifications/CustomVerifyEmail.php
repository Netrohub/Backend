<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

class CustomVerifyEmail extends VerifyEmail
{
    /**
     * Get the verification URL for the given notifiable.
     */
    protected function verificationUrl($notifiable)
    {
        // Generate a signed URL that expires in 60 minutes
        $backendUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );

        // Extract the signature and expiration from backend URL
        $parsedUrl = parse_url($backendUrl);
        parse_str($parsedUrl['query'] ?? '', $queryParams);

        // Build frontend URL with verification parameters
        $frontendUrl = config('app.frontend_url') . '/verify-email?' . http_build_query([
            'id' => $notifiable->getKey(),
            'hash' => sha1($notifiable->getEmailForVerification()),
            'expires' => $queryParams['expires'] ?? '',
            'signature' => $queryParams['signature'] ?? '',
        ]);

        return $frontendUrl;
    }

    /**
     * Build the mail representation of the notification.
     */
    public function toMail($notifiable)
    {
        $verificationUrl = $this->verificationUrl($notifiable);

        return (new MailMessage)
            ->subject('توثيق البريد الإلكتروني - ' . config('app.name'))
            ->greeting('مرحباً ' . $notifiable->name . '!')
            ->line('شكراً لتسجيلك في ' . config('app.name'))
            ->line('يرجى الضغط على الزر أدناه لتوثيق بريدك الإلكتروني:')
            ->action('توثيق البريد الإلكتروني', $verificationUrl)
            ->line('هذا الرابط صالح لمدة 60 دقيقة.')
            ->line('إذا لم تقم بإنشاء حساب، لا داعي لأي إجراء.')
            ->salutation('تحياتنا،' . "\n" . 'فريق ' . config('app.name'));
    }
}

