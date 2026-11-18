<?php

declare(strict_types=1);

namespace StarterAcceptance;

use Redis;
use RedisException;
use RuntimeException;
use Webman\Http\Request;
use Z0x\WebmanStarter\saas\contract\SmsSenderInterface;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\domain\SubmissionStatus;
use Z0x\WebmanStarter\saas\http\ClientAddress;
use Z0x\WebmanStarter\saas\invitationverification\InvitationVerificationAuditInterface;
use Z0x\WebmanStarter\saas\invitationverification\InvitationVerificationService;
use Z0x\WebmanStarter\saas\verification\RatePolicy;
use Z0x\WebmanStarter\saas\verification\RedisVerification;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/fixtures.php';

const ISSUE_15_NOW = 1767268770;
const ISSUE_15_IP = '192.0.2.15';
const ISSUE_15_SECRET = 'issue-15-disposable-test-secret-only';

final class InvitationTestSender implements SmsSenderInterface
{
    /** @var list<array{phone:string,purpose:string,code:string}> */
    public array $messages = [];
    public SubmissionStatus $result = SubmissionStatus::Accepted;

    public function send(MainlandMobileNumber $phone, string $purpose, string $code): SubmissionStatus
    {
        $this->messages[] = ['phone' => $phone->e164, 'purpose' => $purpose, 'code' => $code];

        return $this->result;
    }
}

final class InvitationTestAudit implements InvitationVerificationAuditInterface
{
    /** @var list<array{action:string,invitationId:int,maskedPhone:string,now:int}> */
    public array $events = [];

    public function record(string $action, int $invitationId, string $maskedPhone, int $now): void
    {
        $this->events[] = compact('action', 'invitationId', 'maskedPhone', 'now');
    }
}

function invitationError(string $code, callable $operation, int $status = 401): void
{
    try {
        $operation();
    } catch (BusinessException $error) {
        check($error->code() === $code && $error->status() === $status,
            "Expected $status $code, received {$error->status()} {$error->code()}");

        return;
    }

    throw new RuntimeException("Expected $code, but operation succeeded");
}

/** @return array{InvitationVerificationService,InvitationTestSender,InvitationTestAudit} */
function invitationVerification(Redis $redis, string $namespace, ?RatePolicy $policy = null): array
{
    $sender = new InvitationTestSender();
    $audit = new InvitationTestAudit();
    $verification = new RedisVerification($redis, $namespace, ISSUE_15_SECRET, $policy ?? new RatePolicy());

    return [new InvitationVerificationService($verification, $sender, $audit), $sender, $audit];
}

function testInvitationExchange(Redis $redis): void
{
    [$service, $sender] = invitationVerification($redis, 'qa15:exchange');
    $phone = MainlandMobileNumber::fromInput('13800138000');
    $canonical = MainlandMobileNumber::fromInput('+86 13800138000');
    $now = ISSUE_15_NOW;
    $challenge = $service->send(10, $phone, ISSUE_15_IP, $now);
    [$message] = $sender->messages;
    check($message['phone'] === '+8613800138000' && $message['purpose'] === 'invitation', 'SMS used the wrong scope');
    check(preg_match('/\A[0-9]{6}\z/', $message['code']) === 1, 'SMS was missing its six-digit code');
    $result = $service->verify(10, $canonical, $challenge, $message['code'], $now + 299);
    check($result['expires_at'] === $now + 899, 'Invitation proof did not start its own ten-minute window');
    invitationError('credential_expired', fn () => $service->verify(10, $phone, $challenge, $message['code'], $now + 299));
    $service->requireCredential(10, $canonical, $result['credential'], $now + 898);
    invitationError('credential_expired', fn () => $service->requireCredential(10, $phone, $result['credential'], $now + 899));
    echo "PASS invitation OTP exchange and independent proof expiry\n";
}

function testInvitationBinding(Redis $redis): void
{
    [$service, $sender] = invitationVerification($redis, 'qa15:binding');
    $phone = MainlandMobileNumber::fromInput('13800138000');
    $otherPhone = MainlandMobileNumber::fromInput('13800138001');
    $now = ISSUE_15_NOW;
    $challenge = $service->send(20, $phone, ISSUE_15_IP, $now);
    $code = $sender->messages[0]['code'];
    $d1 = new RedisVerification($redis, 'qa15:binding', ISSUE_15_SECRET, new RatePolicy());
    foreach (['login', 'account_security', 'rebind'] as $purpose) {
        // Other entry points cannot spend invitation attempts, and invitation sends cannot spend their quotas.
        invitationError('purpose_mismatch', fn () => $d1->verify($challenge, $phone->e164, $purpose, 'invitation', '20', $code, $now));
        check($d1->reserve($phone->e164, ISSUE_15_IP, $purpose, $now), 'Invitation send spent another purpose quota');
        $other = $d1->issue($phone->e164, $purpose, 'invitation', '20', $now);
        invitationError('purpose_mismatch', fn () => $service->verify(20, $phone, $other['id'], $other['code'], $now));
        $d1->verify($other['id'], $phone->e164, $purpose, 'invitation', '20', $other['code'], $now);
    }
    invitationError('purpose_mismatch', fn () => $service->verify(21, $phone, $challenge, $code, $now));
    invitationError('purpose_mismatch', fn () => $service->verify(20, $otherPhone, $challenge, $code, $now));
    foreach (['qa15:production', 'different:binding'] as $namespace) {
        [$other] = invitationVerification($redis, $namespace);
        $other->send(20, $phone, ISSUE_15_IP, $now);
        invitationError('credential_expired', fn () => $other->verify(20, $phone, $challenge, $code, $now));
    }
    for ($attempt = 0; $attempt < 4; $attempt++) {
        invitationError('verification_code_invalid', fn () => $service->verify(20, $phone, $challenge, wrongInvitationCode($code), $now));
    }
    $proof = $service->verify(20, $phone, $challenge, $code, $now)['credential'];
    invitationError('purpose_mismatch', fn () => $service->requireCredential(21, $phone, $proof, $now));
    invitationError('purpose_mismatch', fn () => $service->requireCredential(20, $otherPhone, $proof, $now));
    foreach (['qa15:production', 'different:binding'] as $namespace) {
        [$other] = invitationVerification($redis, $namespace);
        invitationError('credential_expired', fn () => $other->requireCredential(20, $phone, $proof, $now));
    }
    $service->forgetCredential(21, $phone, $proof);
    $service->requireCredential(20, $phone, $proof, $now);
    $service->forgetCredential(20, $phone, $proof);
    $service->forgetCredential(20, $phone, $proof);
    invitationError('credential_expired', fn () => $service->requireCredential(20, $phone, $proof, $now));
    echo "PASS invitation, phone, purpose, product and environment binding and post-commit proof cleanup\n";
}

function wrongInvitationCode(string $code): string
{
    return $code === '000000' ? '111111' : '000000';
}

function testInvitationOtpLimits(Redis $redis): void
{
    [$service, $sender] = invitationVerification($redis, 'qa15:attempts');
    $phone = MainlandMobileNumber::fromInput('13800138000');
    $now = ISSUE_15_NOW;
    $first = $service->send(30, $phone, ISSUE_15_IP, $now);
    $code = $sender->messages[0]['code'];
    $wrong = wrongInvitationCode($code);
    for ($attempt = 0; $attempt < 4; $attempt++) {
        invitationError('verification_code_invalid', fn () => $service->verify(30, $phone, $first, $wrong, $now));
    }
    invitationError('verification_attempts_exceeded', fn () => $service->verify(30, $phone, $first, $wrong, $now));
    invitationError('credential_expired', fn () => $service->verify(30, $phone, $first, $code, $now));
    $second = $service->send(30, $phone, ISSUE_15_IP, $now + 60);
    invitationError('credential_expired', fn () => $service->verify(30, $phone, $second, $sender->messages[1]['code'], $now + 360));

    [$tight, $tightSender] = invitationVerification($redis, 'qa15:tight', new RatePolicy(ttlSeconds: 30, maxAttempts: 2));
    $first = $tight->send(30, $phone, ISSUE_15_IP, $now);
    $code = $tightSender->messages[0]['code'];
    $wrong = wrongInvitationCode($code);
    invitationError('verification_code_invalid', fn () => $tight->verify(30, $phone, $first, $wrong, $now));
    invitationError('verification_attempts_exceeded', fn () => $tight->verify(30, $phone, $first, $wrong, $now));
    invitationError('credential_expired', fn () => $tight->verify(30, $phone, $first, $code, $now));
    $second = $tight->send(30, $phone, ISSUE_15_IP, $now + 60);
    invitationError('credential_expired', fn () => $tight->verify(30, $phone, $second, $tightSender->messages[1]['code'], $now + 90));
    echo "PASS default and tightened OTP deadlines and attempt limits\n";
}

function testInvitationResend(Redis $redis): void
{
    [$service, $sender, $audit] = invitationVerification($redis, 'qa15:retry');
    $phone = MainlandMobileNumber::fromInput('13800138000');
    $now = ISSUE_15_NOW;
    $sender->result = SubmissionStatus::Rejected;
    invitationError('sms_submission_failed', fn () => $service->send(40, $phone, ISSUE_15_IP, $now), 502);
    invitationError('sms_rate_limited', fn () => $service->send(40, $phone, ISSUE_15_IP, $now + 59), 429);
    $sender->result = SubmissionStatus::TimedOut;
    invitationError('sms_submission_timeout', fn () => $service->send(40, $phone, ISSUE_15_IP, $now + 60), 504);
    invitationError('sms_rate_limited', fn () => $service->send(40, $phone, ISSUE_15_IP, $now + 119), 429);
    $sender->result = SubmissionStatus::Accepted;
    $first = $service->send(40, $phone, ISSUE_15_IP, $now + 120);
    $second = $service->send(40, $phone, ISSUE_15_IP, $now + 180);
    check(count($sender->messages) === 4, 'A rate-limited resend reached the provider');
    invitationError('credential_expired', fn () => $service->verify(40, $phone, $first, $sender->messages[2]['code'], $now + 181));
    $code = $sender->messages[3]['code'];
    invitationError('verification_code_invalid', fn () => $service->verify(40, $phone, $second, wrongInvitationCode($code), $now + 181));
    $proof = $service->verify(40, $phone, $second, $code, $now + 181);
    $third = $service->send(40, $phone, ISSUE_15_IP, $now + 240);
    $nextProof = $service->verify(40, $phone, $third, $sender->messages[4]['code'], $now + 241);
    check($nextProof['credential'] !== $proof['credential'] && $nextProof['expires_at'] === $now + 841, 'Fresh verification did not issue its own proof');
    for ($i = 0; $i < 2; $i++) {
        $service->requireCredential(40, $phone, $proof['credential'], $now + 780);
    }
    invitationError('credential_expired', fn () => $service->requireCredential(40, $phone, $proof['credential'], $now + 781));
    $service->requireCredential(40, $phone, $nextProof['credential'], $now + 781);

    check(array_count_values(array_column($audit->events, 'action')) === [
        'sms_submission_failed' => 1, 'sms_rate_limited' => 2, 'sms_challenge_reissued' => 3,
        'sms_submission_timeout' => 1, 'sms_submitted' => 3, 'verification_code_invalid' => 1,
    ], 'Audit must distinguish SMS submission outcomes, resends and wrong codes');
    foreach ($audit->events as $event) {
        check(array_keys($event) === ['action', 'invitationId', 'maskedPhone', 'now']
            && $event['invitationId'] === 40 && $event['maskedPhone'] === '**********8000',
            'Audit includes unexpected fields or a destination that is not masked');
    }
    echo "PASS failed and timed-out submission quotas, resend, independent proof expiry and redacted audit\n";
}

function invitationMobile(int $index): MainlandMobileNumber
{
    return MainlandMobileNumber::fromInput(sprintf('1380013%04d', $index));
}

function testInvitationSendLimits(Redis $redis): void
{
    $phone = invitationMobile(0);
    $now = ISSUE_15_NOW;
    [$service, $sender] = invitationVerification($redis, 'qa15:send-interval');
    $service->send(50, $phone, ISSUE_15_IP, $now);
    // Use the same number in two representations and a different invitation ID after reissuance.
    $samePhone = MainlandMobileNumber::fromInput('+86 138 0013 0000');
    invitationError('sms_rate_limited', fn () => $service->send(51, $samePhone, ISSUE_15_IP, $now + 59), 429);
    $service->send(51, $samePhone, ISSUE_15_IP, $now + 60);
    check(count($sender->messages) === 2, 'Reissue or normalized spelling bypassed the cooldown');

    foreach ([['shanghai', 1767283200, new RatePolicy()], ['utc', 1767312000, new RatePolicy(timezone: 'UTC')]] as [$name, $midnight, $policy]) {
        [$service, $sender] = invitationVerification($redis, 'qa15:' . $name, $policy);
        for ($send = 0; $send < 10; $send++) {
            $service->send(60, $phone, ISSUE_15_IP, $midnight - 601 + 60 * $send);
        }
        invitationError('sms_rate_limited', fn () => $service->send(61, $phone, ISSUE_15_IP, $midnight - 1), 429);
        if ($name === 'utc') {
            invitationError('sms_rate_limited', fn () => $service->send(61, $phone, ISSUE_15_IP, 1767283200), 429);
        }
        $nearMidnight = invitationMobile(1);
        $service->send(62, $nearMidnight, ISSUE_15_IP, $midnight - 10);
        $service->send(61, $phone, ISSUE_15_IP, $midnight);
        invitationError('sms_rate_limited', fn () => $service->send(62, $nearMidnight, ISSUE_15_IP, $midnight), 429);
        $service->send(62, $nearMidnight, ISSUE_15_IP, $midnight + 50);
        check(count($sender->messages) === 13, 'Daily boundary or phone cooldown did not follow the configured timezone');
    }

    [$service, $sender] = invitationVerification($redis, 'qa15:sliding');
    $service->send(100, invitationMobile(0), ISSUE_15_IP, $now);
    for ($i = 1; $i < 20; $i++) {
        $service->send(100 + $i, invitationMobile($i), ISSUE_15_IP, $now + 60);
    }
    invitationError('sms_rate_limited', fn () => $service->send(120, invitationMobile(20), ISSUE_15_IP, $now + 3599), 429);
    $service->send(120, invitationMobile(20), ISSUE_15_IP, $now + 3600);
    invitationError('sms_rate_limited', fn () => $service->send(121, invitationMobile(21), ISSUE_15_IP, $now + 3600), 429);
    check(count($sender->messages) === 21, 'IP window did not expire exactly one send at 60 minutes');

    [$service, $sender] = invitationVerification($redis, 'qa15:maximum', new RatePolicy(ipLimit: 200));
    for ($i = 0; $i < 200; $i++) {
        $service->send(300 + $i, invitationMobile($i), ISSUE_15_IP, $now);
    }
    invitationError('sms_rate_limited', fn () => $service->send(500, invitationMobile(200), ISSUE_15_IP, $now), 429);
    check(count($sender->messages) === 200, 'IP ceiling of 200 was not enforced');

    [$service, $sender] = invitationVerification($redis, 'qa15:tight-quota', new RatePolicy(minIntervalSeconds: 90, dailyLimit: 2, ipLimit: 3));
    $sender->result = SubmissionStatus::Rejected;
    invitationError('sms_submission_failed', fn () => $service->send(70, $phone, ISSUE_15_IP, $now), 502);
    invitationError('sms_rate_limited', fn () => $service->send(70, $phone, ISSUE_15_IP, $now + 89), 429);
    $sender->result = SubmissionStatus::TimedOut;
    invitationError('sms_submission_timeout', fn () => $service->send(70, $phone, ISSUE_15_IP, $now + 90), 504);
    $sender->result = SubmissionStatus::Accepted;
    $service->send(71, invitationMobile(1), ISSUE_15_IP, $now + 90);
    invitationError('sms_rate_limited', fn () => $service->send(72, $phone, '192.0.2.90', $now + 180), 429);
    invitationError('sms_rate_limited', fn () => $service->send(73, invitationMobile(2), ISSUE_15_IP, $now + 180), 429);
    check(count($sender->messages) === 3, 'Provider failures and timeouts must count toward every quota');

    foreach ([
        fn () => new RatePolicy(ipLimit: 201), fn () => new RatePolicy(ipLimit: 0),
        fn () => new RatePolicy(ipLimit: false), fn () => new RatePolicy(ipLimit: '20'),
        fn () => new RatePolicy(minIntervalSeconds: 59), fn () => new RatePolicy(dailyLimit: 11),
        fn () => new RatePolicy(ttlSeconds: 301), fn () => new RatePolicy(maxAttempts: 6),
        fn () => new RatePolicy(maxAttempts: 0), fn () => new RatePolicy(timezone: 'invalid'),
    ] as $invalid) {
        invitationError('rate_limit_config_invalid', $invalid, 500);
    }
    echo "PASS invitation send cooldown, Shanghai and UTC natural days, sliding IP limit, maximum and policy validation\n";
}

function invitationRequest(string $remote, string $forwardedFor): Request
{
    return new class ($remote, $forwardedFor) extends Request {
        public function __construct(private readonly string $remote, string $forwardedFor)
        {
            parent::__construct("POST /invite/send HTTP/1.1\r\nHost: localhost\r\nX-Forwarded-For: $forwardedFor\r\n\r\n");
        }

        public function getRemoteIp(): string
        {
            return $this->remote;
        }
    };
}

function testInvitationTrustedIp(Redis $redis): void
{
    [$service, $sender] = invitationVerification($redis, 'qa15:trusted-address', new RatePolicy(ipLimit: 1));
    $resolver = new ClientAddress(['192.0.2.50', '192.0.2.51']);
    $now = ISSUE_15_NOW;
    $send = static fn (int $index, string $remote, string $forwarded) => $service->send(
        600 + $index, invitationMobile($index), $resolver->resolve(invitationRequest($remote, $forwarded)), $now,
    );
    $send(0, '192.0.2.52', '198.51.100.1');
    invitationError('sms_rate_limited', fn () => $send(1, '::ffff:192.0.2.52', '198.51.100.2'), 429);
    $send(1, '192.0.2.50', '198.51.100.1, 198.51.100.3, 192.0.2.51');
    invitationError('sms_rate_limited', fn () => $send(2, '192.0.2.50', '198.51.100.2, 198.51.100.3, 192.0.2.51'), 429);
    $send(2, '192.0.2.50', '198.51.100.4, 192.0.2.51');
    check(count($sender->messages) === 3, 'Forwarded addresses can either bypass or wrongly merge trusted IP quotas');
    echo "PASS trusted proxy chain, spoofed headers and IPv4-mapped addresses on invitation quotas\n";
}

/** @param list<callable(Redis):mixed> $workers @return list<string> */
function invitationRace(string $socket, array $workers): array
{
    $children = [];
    try {
        foreach ($workers as $worker) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            check($pair !== false, 'Could not open race gate');
            [$gate, $childGate] = $pair;
            stream_set_timeout($gate, 5);
            stream_set_timeout($childGate, 5);
            $pid = pcntl_fork();
            check($pid !== -1, 'Could not start a concurrent worker');
            if ($pid === 0) {
                fclose($gate);
                foreach ($children as [, $parentGate]) {
                    fclose($parentGate);
                }
                try {
                    $redis = new Redis();
                    check($redis->connect($socket, 0, 1.0), 'Concurrent worker could not connect to Redis');
                    $redis->setOption(Redis::OPT_READ_TIMEOUT, 3.0);
                    fwrite($childGate, "ready\n");
                    check(fgets($childGate) === "go\n", 'Concurrent worker did not receive the start signal');
                    $worker($redis);
                    fwrite($childGate, "success\n");
                    exit(0);
                } catch (BusinessException $error) {
                    fwrite($childGate, $error->code() . "\n");
                    exit(0);
                } catch (\Throwable $error) {
                    fwrite(STDERR, $error->getMessage() . "\n");
                    exit(1);
                }
            }
            fclose($childGate);
            $children[] = [$pid, $gate];
        }
        foreach ($children as [, $gate]) {
            check(fgets($gate) === "ready\n", 'Concurrent worker did not reach the race gate');
        }
        foreach ($children as [, $gate]) {
            check(fwrite($gate, "go\n") === 3, 'Could not release concurrent worker');
        }
        $outcomes = [];
        foreach ($children as [, $gate]) {
            $result = fgets($gate);
            check(is_string($result), 'Concurrent worker did not report an outcome');
            $outcomes[] = trim($result);
        }

        return $outcomes;
    } finally {
        foreach ($children as [$pid, $gate]) {
            fclose($gate);
            pcntl_waitpid($pid, $status);
        }
    }
}

/** @param list<string> $expected @param list<string> $actual */
function checkInvitationRace(array $expected, array $actual): void
{
    sort($expected);
    sort($actual);
    check($expected === $actual, 'Unexpected concurrent outcomes: ' . implode(', ', $actual));
}

function testInvitationConcurrency(Redis $redis, string $socket): void
{
    $now = ISSUE_15_NOW;
    $phone = invitationMobile(0);
    [$service] = invitationVerification($redis, 'qa15:race-ip');
    for ($i = 0; $i < 19; $i++) {
        $service->send(700 + $i, invitationMobile($i), ISSUE_15_IP, $now);
    }
    $send = static function (string $namespace, int $index, string $ip, ?int $phoneIndex = null) use ($now): callable {
        return static function (Redis $workerRedis) use ($namespace, $index, $phoneIndex, $ip, $now): void {
            [$worker] = invitationVerification($workerRedis, $namespace);
            $worker->send(700 + $index, invitationMobile($phoneIndex ?? $index), $ip, $now);
        };
    };
    $ipRace = invitationRace($socket, [$send('qa15:race-ip', 19, ISSUE_15_IP), $send('qa15:race-ip', 20, ISSUE_15_IP)]);
    checkInvitationRace(['success', 'sms_rate_limited'], $ipRace);
    invitationError('sms_rate_limited', fn () => $service->send(721, invitationMobile(21), ISSUE_15_IP, $now), 429);
    $loser = $ipRace[0] === 'sms_rate_limited' ? 19 : 20;
    $service->send(700 + $loser, invitationMobile($loser), '192.0.2.90', $now);

    checkInvitationRace(['success', 'sms_rate_limited'], invitationRace($socket, [
        $send('qa15:race-phone', 0, ISSUE_15_IP, 0), $send('qa15:race-phone', 1, '192.0.2.90', 0),
    ]));

    [$service, $sender] = invitationVerification($redis, 'qa15:race-code');
    $first = $service->send(750, $phone, ISSUE_15_IP, $now);
    $verify = static function (string $challenge, string $code, int $time) use ($phone): callable {
        return static function (Redis $workerRedis) use ($challenge, $code, $time, $phone): void {
            [$worker] = invitationVerification($workerRedis, 'qa15:race-code');
            $worker->verify(750, $phone, $challenge, $code, $time);
        };
    };
    $correct = $verify($first, $sender->messages[0]['code'], $now);
    checkInvitationRace(['success', 'credential_expired'], invitationRace($socket, [$correct, $correct]));

    $second = $service->send(750, $phone, ISSUE_15_IP, $now + 60);
    $code = $sender->messages[1]['code'];
    $wrong = wrongInvitationCode($code);
    for ($attempt = 0; $attempt < 4; $attempt++) {
        invitationError('verification_code_invalid', fn () => $service->verify(750, $phone, $second, $wrong, $now + 60));
    }
    [$correctResult, $wrongResult] = invitationRace($socket, [$verify($second, $code, $now + 60), $verify($second, $wrong, $now + 60)]);
    check([$correctResult, $wrongResult] === ['success', 'credential_expired']
        || [$correctResult, $wrongResult] === ['credential_expired', 'verification_attempts_exceeded'],
        'Correct OTP consumption and failure-limit exhaustion were not mutually exclusive');
    invitationError('credential_expired', fn () => $service->verify(750, $phone, $second, $code, $now + 60));

    $third = $service->send(750, $phone, ISSUE_15_IP, $now + 120);
    $wrong = wrongInvitationCode($sender->messages[2]['code']);
    for ($attempt = 0; $attempt < 3; $attempt++) {
        invitationError('verification_code_invalid', fn () => $service->verify(750, $phone, $third, $wrong, $now + 120));
    }
    $wrongRequest = $verify($third, $wrong, $now + 120);
    checkInvitationRace(['verification_code_invalid', 'verification_attempts_exceeded', 'credential_expired'],
        invitationRace($socket, [$wrongRequest, $wrongRequest, $wrongRequest]));
    echo "PASS concurrent Redis send reservations, single correct consumption and atomic exhaustion\n";
}

check(extension_loaded('pcntl') && extension_loaded('redis'), 'Install PHP pcntl and redis extensions for invitation tests');
pcntl_async_signals(true);
foreach ([SIGINT, SIGTERM] as $signal) {
    pcntl_signal($signal, static function (int $signal): never {
        throw new RuntimeException("Interrupted by signal $signal");
    });
}
$dir = sys_get_temp_dir() . '/i15-' . bin2hex(random_bytes(6));
check(mkdir($dir, 0700), 'Could not create disposable Redis directory');
$socket = "$dir/redis.sock";
$process = null;
$client = new Redis();
try {
    $process = new Process([
        'redis-server', '--port', '0', '--unixsocket', $socket, '--unixsocketperm', '700', '--dir', $dir,
        '--daemonize', 'no', '--save', '', '--appendonly', 'no',
    ], $dir, ['PATH' => (string) getenv('PATH')], "$dir/redis.log");
    $deadline = microtime(true) + 5;
    while (true) {
        if (file_exists($socket)) {
            try {
                if ($client->connect($socket, 0, 0.2) && pingSucceeded($client->ping())) {
                    break;
                }
            } catch (RedisException) {
                // The socket can appear just before Redis starts accepting requests.
            }
        }
        check($process->running() && microtime(true) < $deadline, "Redis failed to start: {$process->output()}");
        usleep(10_000);
    }
    testInvitationExchange($client);
    testInvitationBinding($client);
    testInvitationOtpLimits($client);
    testInvitationResend($client);
    testInvitationSendLimits($client);
    testInvitationTrustedIp($client);
    testInvitationConcurrency($client, $socket);
} finally {
    if ($client->isConnected()) {
        $client->close();
    }
    $process?->stop();
    foreach ([$socket, "$dir/redis.log"] as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }
    rmdir($dir);
}
