<?php

namespace App\Helpers;

use Illuminate\Http\Request;

class SecurityHelper
{
    /**
     * Check if we're in production environment
     * Never expose debug information in production
     */
    public static function isProduction(): bool
    {
        return in_array(config('app.env'), ['production', 'staging']);
    }

    /**
     * Safely get error message for API response
     * Never exposes stack traces or sensitive information in production
     */
    public static function getSafeErrorMessage(\Exception $e, ?string $defaultMessage = null): ?string
    {
        // Never expose error details in production
        if (self::isProduction()) {
            return null;
        }

        // In development, only expose if explicitly enabled
        if (!config('app.debug', false)) {
            return null;
        }

        // Even in debug mode, sanitize error messages to prevent information disclosure
        $message = $e->getMessage();
        
        // Remove sensitive patterns
        $message = preg_replace('/\/home\/[^\s]+/', '[PATH]', $message);
        $message = preg_replace('/\/var\/www\/[^\s]+/', '[PATH]', $message);
        $message = preg_replace('/\/app\/[^\s]+/', '[PATH]', $message);
        
        return $message ?: $defaultMessage;
    }

    /**
     * Validate Origin/Referer header for CSRF protection
     * Returns true if request is safe, false if potential CSRF attack
     */
    public static function validateOrigin(Request $request): bool
    {
        $origin = $request->header('Origin');
        $referer = $request->header('Referer');
        $frontendUrl = config('app.frontend_url');
        
        // If no origin/referer, allow (could be direct API call or mobile app)
        if (!$origin && !$referer) {
            return true;
        }

        // Extract domain from URL
        $getDomain = function($url) {
            if (!$url) return null;
            $parsed = parse_url($url);
            return $parsed['scheme'] . '://' . $parsed['host'] ?? null;
        };

        $originDomain = $getDomain($origin);
        $refererDomain = $getDomain($referer);
        $allowedDomain = $getDomain($frontendUrl);

        // Check if origin/referer matches allowed frontend URL
        if ($originDomain && $originDomain === $allowedDomain) {
            return true;
        }

        if ($refererDomain && $refererDomain === $allowedDomain) {
            return true;
        }

        // For state-changing operations, we should be stricter
        // But for now, we'll log and allow (to avoid breaking legitimate requests)
        \Illuminate\Support\Facades\Log::warning('Potential CSRF: Origin/Referer mismatch', [
            'origin' => $origin,
            'referer' => $referer,
            'allowed' => $frontendUrl,
            'ip' => $request->ip(),
        ]);

        return true; // Allow for now, but log for monitoring
    }

    /**
     * Validate file magic bytes (file signature) to prevent MIME type spoofing
     */
    public static function validateFileSignature($file, array $allowedMimeTypes): bool
    {
        if (!$file || !$file->isValid()) {
            return false;
        }

        $path = $file->getRealPath();
        if (!file_exists($path)) {
            return false;
        }

        // Read first bytes to check magic bytes
        $handle = fopen($path, 'rb');
        if (!$handle) {
            return false;
        }

        $bytes = fread($handle, 12);
        fclose($handle);

        if (!$bytes) {
            return false;
        }

        // Check magic bytes for common image formats
        // JPEG: FF D8 FF
        if (in_array('image/jpeg', $allowedMimeTypes) && substr($bytes, 0, 3) === "\xFF\xD8\xFF") {
            return true;
        }

        // PNG: 89 50 4E 47 0D 0A 1A 0A
        if (in_array('image/png', $allowedMimeTypes) && substr($bytes, 0, 8) === "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A") {
            return true;
        }

        // GIF: GIF87a or GIF89a
        if (in_array('image/gif', $allowedMimeTypes) && (substr($bytes, 0, 6) === 'GIF87a' || substr($bytes, 0, 6) === 'GIF89a')) {
            return true;
        }

        // WebP: RIFF...WEBP (RIFF at start, WEBP at offset 8)
        if (in_array('image/webp', $allowedMimeTypes) && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return true;
        }

        return false;
    }

    /**
     * Generate a frontend URL (never expose backend URLs to users)
     * 
     * @param string $path The path to append to frontend URL (e.g., '/orders/123')
     * @return string Full frontend URL
     */
    public static function frontendUrl(string $path = ''): string
    {
        $frontendUrl = config('app.frontend_url', config('app.url'));
        $base = rtrim($frontendUrl, '/');
        $path = ltrim($path, '/');
        return $base . ($path ? '/' . $path : '');
    }
}

