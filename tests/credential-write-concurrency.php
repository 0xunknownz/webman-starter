<?php

declare(strict_types=1);

namespace StarterAcceptance;

use Closure;
use Fiber;
use Illuminate\Database\Capsule\Manager;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\domain\SubmissionStatus;
use Z0x\WebmanStarter\saas\email\EmailAddress;
use Z0x\WebmanStarter\saas\email\EmailSenderInterface;
use Z0x\WebmanStarter\saas\email\EmailSignInService;
use Z0x\WebmanStarter\saas\email\EmailVerification;
use Z0x\WebmanStarter\saas\email\IlluminateEmailAccountRepository;
use Z0x\WebmanStarter\saas\email\Password;
use Z0x\WebmanStarter\saas\email\RedisPasswordLoginLimiter;
use Z0x\WebmanStarter\saas\infrastructure\StarterDatabaseConfig;
use Z0x\WebmanStarter\saas\rebind\IlluminatePhoneChangeRepository;
use Z0x\WebmanStarter\saas\rebind\PhoneChangeFlow;
use Z0x\WebmanStarter\saas\reset\IlluminatePasswordResetRepository;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\saas\session\SessionService;
use Z0x\WebmanStarter\saas\verification\RatePolicy;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

require __DIR__ . '/fixtures.php';

check(getenv('STARTER_DB_DATABASE') === 'qa_credentialwrites' && getenv('STARTER_DB_USERNAME') === 'starter_qa',
    'Run credential write regression through the disposable QA-01 fixture');
require getcwd() . '/vendor/autoload.php';

/** @param Closure(SessionIdentity):void $write @param Closure():void $revoke */
function assertRevokedWrite(SessionService $sessions, int $accountId, int $now, Closure $write, Closure $revoke): void
{
    $purpose = SessionService::PURPOSE_ORGANIZATION;
    $credentials = $sessions->issue($accountId, $purpose, $now);
    // Suspend at the controller-to-service boundary. Resume only after the other connection commits.
    $request = new Fiber(static function () use ($sessions, $credentials, $purpose, $now, $write): void {
        $identity = $sessions->authenticate($credentials['access_token'], $purpose, $now);
        Fiber::suspend();
        $write($identity);
    });
    $request->start();
    check($request->isSuspended(), 'Credential write did not reach the authenticated boundary');
    $revoke();
    try {
        $request->resume();
    } catch (BusinessException $exception) {
        check($exception->code() === 'token_invalid', 'Revoked credential write returned an unexpected error');

        return;
    }
    check(false, 'Credential write succeeded after its session was revoked');
}

$db = new Manager();
$config = StarterDatabaseConfig::fromEnvironment();
$db->addConnection($config, 'request');
$db->addConnection($config, 'revocation');
$requestDb = $db->getConnection('request');
$revocationDb = $db->getConnection('revocation');
$redis = new \Redis();
check($redis->connect('127.0.0.1', (int) getenv('STARTER_REDIS_PORT'), 1)
    && $redis->auth((string) getenv('STARTER_REDIS_PASSWORD')), 'Could not connect to disposable Redis');

$secret = bin2hex(random_bytes(32));
$prefix = 'qa01:credential-writes';
$sessions = new SessionService($redis, $prefix, $secret, $requestDb);
$verification = new EmailVerification($redis, $prefix, $secret, RatePolicy::email());
$accounts = new IlluminateEmailAccountRepository($requestDb);
$email = new EmailSignInService($accounts, $verification, new class implements EmailSenderInterface {
    public function send(string $email, #[\SensitiveParameter] string $code, string $purpose): SubmissionStatus
    {
        throw new \LogicException('Credential write checks do not send email');
    }
}, new RedisPasswordLoginLimiter($redis, $prefix, $secret), $sessions);
$reset = new IlluminatePasswordResetRepository($revocationDb);
$phoneChange = new IlluminatePhoneChangeRepository($revocationDb);
$now = time();
$utc = gmdate('Y-m-d H:i:s', $now);
$password = 'qa-initial-password';
$newPassword = 'qa-reset-password';
$unbound = EmailAddress::fromInput('binding@example.test');

$seed = static function (string $phone, ?string $address = null) use ($requestDb, $utc, $password): int {
    return (int) $requestDb->table('starter_accounts')->insertGetId([
        'phone' => $phone,
        'email' => $address,
        'email_verified_at' => $address === null ? null : $utc,
        'password_hash' => $address === null ? null : Password::hash($password),
        'created_at' => $utc,
        'updated_at' => $utc,
    ]);
};
$recover = static function (PhoneChangeFlow $flow) use ($phoneChange, $now): void {
    // Start at the durable transition with already-verified fixture state. QA-01 covers proof and eligibility separately.
    $phoneChange->create($flow, $now);
    $phoneChange->withFlow($flow->id, $flow->accountId, static function (PhoneChangeFlow $locked) use ($phoneChange, $now): void {
        $phoneChange->commitChange($locked, $now);
    });
    check($phoneChange->account($flow->accountId)['phone'] === $flow->newPhone->e164,
        'Recovery fixture did not commit the new number');
};

$resetAddress = EmailAddress::fromInput('reset-write@example.test');
$resetAccount = $seed('+8613800138011', $resetAddress->value);
$setPassword = static function (SessionIdentity $identity) use ($email, $now): void {
    $email->setPassword($identity, 'qa-deferred-password', $now);
};
assertRevokedWrite($sessions, $resetAccount, $now, $setPassword,
    static fn () => $reset->withLockedAccount($resetAccount,
        static fn () => $reset->reset($resetAccount, Password::hash($newPassword), $now)));
$email->login($resetAddress, $newPassword, SessionService::PURPOSE_ORGANIZATION, $now);
echo "PASS password reset rejects a previously authenticated password write and preserves the reset password\n";

$recoveryAddress = EmailAddress::fromInput('recovery-write@example.test');
$recoveryAccount = $seed('+8613800138012', $recoveryAddress->value);
$emailRecovery = new PhoneChangeFlow(
    bin2hex(random_bytes(16)), $recoveryAccount, MainlandMobileNumber::fromInput('+8613800138012'),
    $phoneChange->account($recoveryAccount)['phoneEpoch'], MainlandMobileNumber::fromInput('+8613900139012'),
    'email_recovery', $now + 600, $now + 600, $recoveryAccount,
);
assertRevokedWrite($sessions, $recoveryAccount, $now, $setPassword, static fn () => $recover($emailRecovery));
$email->login($recoveryAddress, $password, SessionService::PURPOSE_ORGANIZATION, $now);
echo "PASS phone recovery rejects a previously authenticated password write and preserves the existing password\n";

$bindingAccount = $seed('+8613800138013');
$account = $accounts->account($bindingAccount);
check($account !== null, 'Missing binding fixture account');
$challenge = $verification->issue($unbound->value, EmailVerification::BIND, (string) $bindingAccount,
    (string) $account['email_epoch'], $now);
$bind = static function (SessionIdentity $identity) use ($email, $unbound, $challenge, $now): void {
    $email->bind($identity, $unbound, $challenge['id'], $challenge['code'], $now);
};
$operatorId = (int) $revocationDb->table('starter_platform_admins')->where('status', 'active')->value('account_id');
check($operatorId > 0 && $operatorId !== $bindingAccount, 'Missing independent platform operator fixture');
$manualRecovery = new PhoneChangeFlow(
    bin2hex(random_bytes(16)), $bindingAccount, MainlandMobileNumber::fromInput('+8613800138013'),
    $phoneChange->account($bindingAccount)['phoneEpoch'], MainlandMobileNumber::fromInput('+8613900139013'),
    PhoneChangeFlow::MANUAL_RECOVERY, $now + 600, $now + 600, $operatorId,
    reasonRef: 'qa-credential-write', recoveryReason: 'lost_phone', evidenceChannel: 'registered_contact',
);
assertRevokedWrite($sessions, $bindingAccount, $now, $bind, static fn () => $recover($manualRecovery));
check($accounts->emailOwner($unbound) === null, 'Revoked request bound an email');
$freshSession = $sessions->issue($bindingAccount, SessionService::PURPOSE_ORGANIZATION, $now);
$bind($sessions->authenticate($freshSession['access_token'], SessionService::PURPOSE_ORGANIZATION, $now));
check($accounts->emailOwner($unbound) === $bindingAccount, 'Rejected binding consumed the proof');
echo "PASS phone recovery rejects a previously authenticated email binding without consuming the proof\n";
