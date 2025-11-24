# Listing Categories Implementation

## Overview

The backend has been updated to support all game categories defined in the frontend. Categories are now centrally managed through a constants class with validation.

## Changes Made

### 1. Created `ListingCategories` Constants Class

**File:** `backend/app/Constants/ListingCategories.php`

This class defines all valid listing categories:

**Gaming Categories:**
- `wos_accounts` - Whiteout Survival
- `pure_sniper_accounts` - Pure Sniper
- `age_of_empires_accounts` - Age of Empires Mobile
- `honor_of_kings_accounts` - Honor of Kings
- `pubg_accounts` - PUBG Mobile
- `fortnite_accounts` - Fortnite

**Social Media Categories:**
- `tiktok_accounts` - TikTok
- `instagram_accounts` - Instagram

### 2. Updated `ListingController`

**Changes:**
- Added category validation in `store()` method
- Added category validation in `update()` method
- Added category validation in `index()` method (when filtering)
- Added new `categories()` method to get all available categories

**Validation:**
- Only categories defined in `ListingCategories::all()` are accepted
- Invalid categories return a 400 error with a list of valid categories

### 3. Updated `ListingFactory`

**Changes:**
- Updated to use `ListingCategories::all()` instead of hardcoded array
- Now includes all new game categories in test data generation

### 4. Added API Endpoint

**New Route:** `GET /api/v1/listings/categories`

**Response:**
```json
{
  "categories": [
    "wos_accounts",
    "pure_sniper_accounts",
    "age_of_empires_accounts",
    "honor_of_kings_accounts",
    "pubg_accounts",
    "fortnite_accounts",
    "tiktok_accounts",
    "instagram_accounts"
  ],
  "gaming": [
    "wos_accounts",
    "pure_sniper_accounts",
    "age_of_empires_accounts",
    "honor_of_kings_accounts",
    "pubg_accounts",
    "fortnite_accounts"
  ],
  "social": [
    "tiktok_accounts",
    "instagram_accounts"
  ],
  "categories_with_names": [
    {
      "value": "wos_accounts",
      "label": "Whiteout Survival"
    },
    {
      "value": "pure_sniper_accounts",
      "label": "Pure Sniper"
    },
    // ... etc
  ]
}
```

## Usage

### Creating a Listing with Category

```php
POST /api/v1/listings
{
  "title": "My Game Account",
  "description": "Description here",
  "price": 100.00,
  "category": "pubg_accounts", // Must be a valid category
  "images": [],
  "account_email": "account@example.com",
  "account_password": "password123"
}
```

### Filtering Listings by Category

```php
GET /api/v1/listings?category=pubg_accounts
```

### Getting All Categories

```php
GET /api/v1/listings/categories
```

## Helper Methods

The `ListingCategories` class provides several helper methods:

- `ListingCategories::all()` - Get all categories
- `ListingCategories::gaming()` - Get gaming categories only
- `ListingCategories::social()` - Get social media categories only
- `ListingCategories::isValid($category)` - Check if category is valid
- `ListingCategories::isGaming($category)` - Check if category is gaming
- `ListingCategories::isSocial($category)` - Check if category is social
- `ListingCategories::getDisplayName($category)` - Get human-readable name

## Adding New Categories

To add a new category:

1. Add a constant to `ListingCategories` class:
   ```php
   const GAMING_NEW_GAME = 'new_game_accounts';
   ```

2. Add it to the appropriate array method (`gaming()`, `social()`, or both)

3. Add display name in `getDisplayName()` method

4. The validation will automatically include the new category

## Backward Compatibility

- Existing listings with old categories will continue to work
- However, new listings must use valid categories
- The `index()` method validates categories when filtering, but doesn't reject listings with invalid categories (for backward compatibility)

## Testing

All existing tests should continue to pass. The `ListingFactory` now uses all valid categories, so test data will include the new game categories.

