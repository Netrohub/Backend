<?php

namespace App\Constants;

/**
 * Listing Categories Constants
 * 
 * Defines all valid listing categories for the platform.
 * Categories are organized by type: gaming accounts and social media accounts.
 */
class ListingCategories
{
    // Gaming Account Categories
    const GAMING_WOS = 'wos_accounts';
    const GAMING_PURE_SNIPER = 'pure_sniper_accounts';
    const GAMING_AGE_OF_EMPIRES = 'age_of_empires_accounts';
    const GAMING_HONOR_OF_KINGS = 'honor_of_kings_accounts';
    const GAMING_PUBG = 'pubg_accounts';
    const GAMING_FORTNITE = 'fortnite_accounts';

    // Social Media Account Categories
    const SOCIAL_TIKTOK = 'tiktok_accounts';
    const SOCIAL_INSTAGRAM = 'instagram_accounts';

    /**
     * Get all valid categories
     * 
     * @return array<string>
     */
    public static function all(): array
    {
        return [
            // Gaming
            self::GAMING_WOS,
            self::GAMING_PURE_SNIPER,
            self::GAMING_AGE_OF_EMPIRES,
            self::GAMING_HONOR_OF_KINGS,
            self::GAMING_PUBG,
            self::GAMING_FORTNITE,
            // Social
            self::SOCIAL_TIKTOK,
            self::SOCIAL_INSTAGRAM,
        ];
    }

    /**
     * Get gaming categories only
     * 
     * @return array<string>
     */
    public static function gaming(): array
    {
        return [
            self::GAMING_WOS,
            self::GAMING_PURE_SNIPER,
            self::GAMING_AGE_OF_EMPIRES,
            self::GAMING_HONOR_OF_KINGS,
            self::GAMING_PUBG,
            self::GAMING_FORTNITE,
        ];
    }

    /**
     * Get social media categories only
     * 
     * @return array<string>
     */
    public static function social(): array
    {
        return [
            self::SOCIAL_TIKTOK,
            self::SOCIAL_INSTAGRAM,
        ];
    }

    /**
     * Check if a category is valid
     * 
     * @param string $category
     * @return bool
     */
    public static function isValid(string $category): bool
    {
        return in_array($category, self::all(), true);
    }

    /**
     * Check if a category is a gaming category
     * 
     * @param string $category
     * @return bool
     */
    public static function isGaming(string $category): bool
    {
        return in_array($category, self::gaming(), true);
    }

    /**
     * Check if a category is a social media category
     * 
     * @param string $category
     * @return bool
     */
    public static function isSocial(string $category): bool
    {
        return in_array($category, self::social(), true);
    }

    /**
     * Get category display name (for API responses)
     * 
     * @param string $category
     * @return string
     */
    public static function getDisplayName(string $category): string
    {
        $names = [
            self::GAMING_WOS => 'Whiteout Survival',
            self::GAMING_PURE_SNIPER => 'Pure Sniper',
            self::GAMING_AGE_OF_EMPIRES => 'Age of Empires Mobile',
            self::GAMING_HONOR_OF_KINGS => 'Honor of Kings',
            self::GAMING_PUBG => 'PUBG Mobile',
            self::GAMING_FORTNITE => 'Fortnite',
            self::SOCIAL_TIKTOK => 'TikTok',
            self::SOCIAL_INSTAGRAM => 'Instagram',
        ];

        return $names[$category] ?? $category;
    }
}

