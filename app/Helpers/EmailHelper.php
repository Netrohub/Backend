<?php

namespace App\Helpers;

class EmailHelper
{
    /**
     * Get the logo URL for emails
     * Returns the public URL of the logo image
     */
    public static function getLogoUrl(): string
    {
        $frontendUrl = config('app.frontend_url');
        
        if (empty($frontendUrl)) {
            $frontendUrl = config('app.url');
        }
        
        if (empty($frontendUrl)) {
            $frontendUrl = 'https://nxoland.com';
        }
        
        $base = rtrim($frontendUrl, '/');
        return $base . '/nxoland-new-logo.png';
    }

    /**
     * Get logo HTML for emails
     * Returns an HTML img tag with the logo
     */
    public static function getLogoHtml(int $width = 120, int $height = 120): string
    {
        $logoUrl = self::getLogoUrl();
        return sprintf(
            '<img src="%s" alt="NXOLand Logo" width="%d" height="%d" style="max-width: 100%%; height: auto; display: block; margin: 0 auto;">',
            htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8'),
            $width,
            $height
        );
    }

    /**
     * Get branded email header HTML
     * Returns HTML for email header with logo
     */
    public static function getEmailHeaderHtml(): string
    {
        $logoHtml = self::getLogoHtml(120, 120);
        return sprintf(
            '<div style="text-align: center; padding: 20px 0; background-color: #0f1a2e; border-bottom: 2px solid #1e3a5f;">
                %s
            </div>',
            $logoHtml
        );
    }
}

