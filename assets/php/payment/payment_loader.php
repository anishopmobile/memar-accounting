<?php
/**
 * payment_loader.php — Requires all payment module files in dependency order.
 *
 * Include this single file wherever the payment module is used. It expects
 * `$mysqli` (already-connected) and the existing boot/auth/session when used
 * inside the admin application.
 */
declare(strict_types=1);

require_once __DIR__ . '/../jdf.php'; // jdate() used by transaction serialization
require_once __DIR__ . '/payment_money.php';
require_once __DIR__ . '/payment_state.php';
require_once __DIR__ . '/payment_models.php';
require_once __DIR__ . '/payment_validation.php';
require_once __DIR__ . '/payment_form_parser.php';
require_once __DIR__ . '/payment_form_repository.php';
require_once __DIR__ . '/payment_transaction_repository.php';
require_once __DIR__ . '/payment_settings_repository.php';
require_once __DIR__ . '/payment_notification_repository.php';
require_once __DIR__ . '/payment_rate_limiter.php';
require_once __DIR__ . '/sms/SmsProviderInterface.php';
require_once __DIR__ . '/sms/FarazSmsProvider.php';
require_once __DIR__ . '/sms/SmsService.php';
require_once __DIR__ . '/gateway/PaymentGatewayInterface.php';
require_once __DIR__ . '/gateway/ZarinPalGateway.php';
require_once __DIR__ . '/payment_service.php';