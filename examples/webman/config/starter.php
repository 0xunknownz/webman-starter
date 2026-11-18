<?php

declare(strict_types=1);

use Z0x\WebmanStarter\saas\email\SymfonyEmailSender;
use Z0x\WebmanStarter\saas\http\ClientAddress;
use Z0x\WebmanStarter\saas\sms\EasySmsSender;
use Z0x\WebmanStarter\saas\verification\RatePolicy;

if (getenv('STARTER_AUTH_ENABLED') !== '1') {
    return [];
}

$integer = static function (string $name, int $default): int {
    $value = getenv($name);
    if ($value === false) {
        return $default;
    }
    $integer = filter_var($value, FILTER_VALIDATE_INT);
    if ($integer === false) {
        throw new InvalidArgumentException('rate_limit_config_invalid');
    }

    return $integer;
};

$enabled = getenv('STARTER_SMS_ENABLED');
if ($enabled !== false && $enabled !== '1') {
    throw new InvalidArgumentException('rate_limit_config_invalid');
}
$timezone = getenv('STARTER_TIMEZONE');
$trustedProxies = getenv('STARTER_TRUSTED_PROXIES');

return [
    'prefix' => (string) getenv('STARTER_REDIS_PREFIX'),
    'secret' => (string) getenv('STARTER_AUTH_SECRET'),
    'sms_policy' => new RatePolicy(
        minIntervalSeconds: $integer('STARTER_SMS_INTERVAL', 60),
        dailyLimit: $integer('STARTER_SMS_PER_DAY', 10),
        ipLimit: $integer('STARTER_SMS_PER_HOUR_PER_IP', 20),
        ttlSeconds: $integer('STARTER_SMS_TTL', 300),
        maxAttempts: $integer('STARTER_SMS_ATTEMPTS', 5),
        timezone: $timezone === false ? 'Asia/Shanghai' : $timezone,
    ),
    'sms_sender' => new EasySmsSender([
        'login' => (string) getenv('STARTER_SMS_TEMPLATE_LOGIN'),
        'rebind' => (string) getenv('STARTER_SMS_TEMPLATE_REBIND'),
        'account_security' => (string) getenv('STARTER_SMS_TEMPLATE_ACCOUNT_SECURITY'),
    ]),
    'email_policy' => RatePolicy::email(
        minIntervalSeconds: $integer('STARTER_EMAIL_INTERVAL', 60),
        dailyLimit: $integer('STARTER_EMAIL_PER_DAY', 10),
        ipLimit: $integer('STARTER_EMAIL_PER_HOUR_PER_IP', 20),
        ttlSeconds: $integer('STARTER_EMAIL_TTL', 600),
        maxAttempts: $integer('STARTER_EMAIL_ATTEMPTS', 5),
        timezone: $timezone === false ? 'Asia/Shanghai' : $timezone,
    ),
    'email_sender' => new SymfonyEmailSender((string) getenv('STARTER_SMTP_DSN'), (string) getenv('STARTER_EMAIL_FROM')),
    'operator_recovery_verifier' => null,
    'operator_recovery_cli_enabled' => getenv('STARTER_OPERATOR_RECOVERY_CLI_ENABLED') === '1',
    'clock' => time(...),
    'client_address' => new ClientAddress(
        $trustedProxies === false || $trustedProxies === '' ? [] : array_map('trim', explode(',', $trustedProxies)),
    ),
];
