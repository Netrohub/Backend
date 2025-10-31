<?php

namespace App\Http\Controllers;

/**
 * Centralized error messages for consistency
 * All error messages should be in English for consistency
 */
class MessageHelper
{
    // Authentication messages
    public const AUTH_INVALID_CREDENTIALS = 'The provided credentials are incorrect.';
    public const AUTH_UNAUTHORIZED = 'Unauthorized access.';
    public const AUTH_EMAIL_NOT_VERIFIED = 'Your email address is not verified.';
    
    // General error messages
    public const ERROR_UNAUTHORIZED = 'Unauthorized.';
    public const ERROR_NOT_FOUND = 'Resource not found.';
    public const ERROR_VALIDATION_FAILED = 'Validation failed.';
    public const ERROR_SERVER_ERROR = 'An error occurred. Please try again.';
    
    // Order messages
    public const ORDER_CANNOT_BUY_OWN = 'Cannot buy your own listing.';
    public const ORDER_NOT_AVAILABLE = 'Listing is not available.';
    public const ORDER_NOT_PENDING = 'Order is not in pending status.';
    public const ORDER_INSUFFICIENT_ESCROW = 'Insufficient escrow balance.';
    
    // Payment messages
    public const PAYMENT_CREATE_FAILED = 'Failed to create payment.';
    
    // Wallet messages
    public const WALLET_INSUFFICIENT_BALANCE = 'Insufficient balance.';
    public const WALLET_WITHDRAWAL_SUBMITTED = 'Withdrawal request submitted.';
    
    // Dispute messages
    public const DISPUTE_ALREADY_EXISTS = 'Dispute already exists for this order.';
    public const DISPUTE_ONLY_ESCROW = 'Dispute can only be created for orders in escrow.';
    
    // Admin messages
    public const ADMIN_CANNOT_DELETE_SELF = 'Cannot delete your own account.';
    public const ADMIN_USER_DELETED = 'User deleted successfully.';
    
    // Webhook messages
    public const WEBHOOK_INVALID_SIGNATURE = 'Invalid signature.';
    public const WEBHOOK_INVALID_PAYLOAD = 'Invalid payload.';
    public const WEBHOOK_PAYMENT_NOT_FOUND = 'Payment not found.';
    public const WEBHOOK_KYC_NOT_FOUND = 'KYC not found.';
    public const WEBHOOK_PROCESSED = 'Webhook processed.';
}

