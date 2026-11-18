<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\sms;

use GuzzleHttp\Exception\ConnectException;
use Hhink\WebmanSms\Sms;
use Overtrue\EasySms\Exceptions\NoGatewayAvailableException;
use Throwable;
use Z0x\WebmanStarter\saas\contract\SmsSenderInterface;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\domain\SubmissionStatus;

final class EasySmsSender implements SmsSenderInterface
{
    /** @param array<string,string> $templates */
    public function __construct(private readonly array $templates)
    {
    }

    public function send(MainlandMobileNumber $phone, string $purpose, string $code): SubmissionStatus
    {
        try {
            Sms::app()->send($phone->e164, [
                'template' => $this->templates[$purpose] ?? '',
                'data' => ['code' => $code],
            ]);

            return SubmissionStatus::Accepted;
        } catch (NoGatewayAvailableException $failure) {
            foreach ($failure->getExceptions() as $exception) {
                if (self::isTimeout($exception)) {
                    return SubmissionStatus::TimedOut;
                }
            }

            return SubmissionStatus::Rejected;
        } catch (Throwable $failure) {
            return self::isTimeout($failure) ? SubmissionStatus::TimedOut : SubmissionStatus::Rejected;
        }
    }

    private static function isTimeout(Throwable $failure): bool
    {
        do {
            if ($failure instanceof ConnectException && ($failure->getHandlerContext()['errno'] ?? null) === 28) {
                return true;
            }
            $failure = $failure->getPrevious();
        } while ($failure !== null);

        return false;
    }
}
