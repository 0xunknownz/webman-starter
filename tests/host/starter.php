<?php

declare(strict_types=1);

require_once __DIR__ . '/../qa-sms/FixtureSmsSender.php';
require_once __DIR__ . '/../qa-sms/FixtureEmailSender.php';

$config = require __DIR__ . '/starter.original.inc';
$state = getenv('QA_SMS_STATE');
$config['sms_sender'] = new FixtureSmsSender($state);
$config['email_sender'] = new FixtureEmailSender($state);
$config['clock'] = static fn (): int => FixtureSmsSender::now($state);

return $config;
