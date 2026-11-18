<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\email;

use InvalidArgumentException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Email;
use Z0x\WebmanStarter\saas\domain\SubmissionStatus;

final class SymfonyEmailSender implements EmailSenderInterface
{
    public function __construct(
        #[\SensitiveParameter] private readonly string $dsn,
        private readonly string $from,
        private readonly float $timeout = 5.0,
    ) {
        if (!is_finite($timeout) || $timeout <= 0) {
            throw new InvalidArgumentException('SMTP timeout must be positive and finite.');
        }
    }

    public function send(string $email, #[\SensitiveParameter] string $code, string $purpose): SubmissionStatus
    {
        if ($this->dsn === '' || $this->from === '') {
            return SubmissionStatus::Rejected;
        }

        try {
            $transport = Transport::fromDsn($this->dsn);
            if (!$transport instanceof SmtpTransport || !($stream = $transport->getStream()) instanceof SocketStream) {
                return SubmissionStatus::Rejected;
            }
            $stream->setTimeout($this->timeout);
            $transport->send((new Email())
                ->from($this->from)
                ->to($email)
                ->subject('账号安全验证码')
                ->text("您的验证码是：$code。请勿向他人透露此验证码。"));
        } catch (TransportExceptionInterface $exception) {
            // Symfony 6.4 uses an untyped TransportException for SMTP socket timeouts.
            // Never report the transport's message or debug transcript, which may contain credentials.
            return $exception->getCode() === 0 && preg_match('/timed\s*out|connection timeout/i', $exception->getMessage()) === 1
                ? SubmissionStatus::TimedOut
                : SubmissionStatus::Rejected;
        } catch (InvalidArgumentException | UnsupportedSchemeException) {
            return SubmissionStatus::Rejected;
        }

        return SubmissionStatus::Accepted;
    }
}
