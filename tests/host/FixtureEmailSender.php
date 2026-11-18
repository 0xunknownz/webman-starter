<?php

declare(strict_types=1);

use Z0x\WebmanStarter\saas\domain\SubmissionStatus;
use Z0x\WebmanStarter\saas\email\EmailSenderInterface;

final class FixtureEmailSender implements EmailSenderInterface
{
    public function __construct(private readonly string $state)
    {
    }

    public function send(string $email, string $code, string $purpose): SubmissionStatus
    {
        $outcomeFile = "$this->state/email-outcome";
        $outcome = is_file($outcomeFile) ? trim(file_get_contents($outcomeFile)) : 'accepted';
        $record = json_encode(compact('email', 'code', 'purpose'), JSON_THROW_ON_ERROR);
        if (file_put_contents("$this->state/email-messages", "$record\n", FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Could not record email submission');
        }

        return match ($outcome) {
            'accepted' => SubmissionStatus::Accepted,
            'failed' => SubmissionStatus::Rejected,
            'timeout' => SubmissionStatus::TimedOut,
        };
    }
}
