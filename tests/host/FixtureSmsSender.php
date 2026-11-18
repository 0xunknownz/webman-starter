<?php

declare(strict_types=1);

use Z0x\WebmanStarter\saas\contract\SmsSenderInterface;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\domain\SubmissionStatus;

final class FixtureSmsSender implements SmsSenderInterface
{
    public function __construct(private readonly string $state)
    {
    }

    public function send(MainlandMobileNumber $phone, string $purpose, string $code): SubmissionStatus
    {
        $outcome = trim(file_get_contents("$this->state/outcome"));
        $record = json_encode(['phone' => $phone->e164, 'code' => $code, 'purpose' => $purpose], JSON_THROW_ON_ERROR);
        if (file_put_contents("$this->state/messages", "$record\n", FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Could not record SMS submission');
        }

        return match ($outcome) {
            'accepted' => SubmissionStatus::Accepted,
            'failed' => SubmissionStatus::Rejected,
            'timeout' => SubmissionStatus::TimedOut,
        };
    }

    public static function now(string $state): int
    {
        [$timestamp, $gate] = explode(' ', trim(file_get_contents("$state/clock")) . ' ', 3);
        if ($gate !== '') {
            // Block the first worker at the clock until another worker reaches the same gate.
            $mine = "$state/$gate-" . getmypid();
            if (!touch($mine)) {
                throw new RuntimeException('Could not enter SMS race gate');
            }
            $deadline = microtime(true) + 3;
            while (count(glob("$state/$gate-*")) < 2) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('SMS race did not reach two Webman workers');
                }
                usleep(1_000);
            }
        }

        return (int) $timestamp;
    }
}
