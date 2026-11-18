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
use Z0x\WebmanStarter\saas\platform\IlluminatePlatformAdminMutationRepository;
use Z0x\WebmanStarter\saas\platform\ManagePlatformAdminsService;
use Z0x\WebmanStarter\saas\profile\IlluminateProfileRepository;
use Z0x\WebmanStarter\saas\profile\ProfileService;
use Z0x\WebmanStarter\saas\rebind\IlluminatePhoneChangeRepository;
use Z0x\WebmanStarter\saas\rebind\PhoneChangeFlow;
use Z0x\WebmanStarter\saas\reset\IlluminatePasswordResetRepository;
use Z0x\WebmanStarter\saas\session\IlluminateSessionAccountRepository;
use Z0x\WebmanStarter\saas\session\SessionIdentity;
use Z0x\WebmanStarter\saas\session\SessionService;
use Z0x\WebmanStarter\saas\verification\RatePolicy;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

require __DIR__ . '/fixtures.php';

check(getenv('STARTER_DB_DATABASE') === 'qa_credentialwrites' && getenv('STARTER_DB_USERNAME') === 'starter_qa',
    'Run credential write regression through the disposable QA-01 fixture');
require getcwd() . '/vendor/autoload.php';

/**
 * @param Closure(SessionIdentity):void $write
 * @param Closure(SessionIdentity):void $revoke
 */
function assertRevokedWrite(
    SessionService $sessions,
    int $accountId,
    int $now,
    Closure $write,
    Closure $revoke,
    string $purpose = SessionService::PURPOSE_ORGANIZATION,
): void {
    $credentials = $sessions->issue($accountId, $purpose, $now);
    // Pause at the controller-to-service boundary until the revocation transition finishes.
    $request = new Fiber(static function () use ($sessions, $credentials, $purpose, $now, $write): void {
        $identity = $sessions->authenticate($credentials['access_token'], $purpose, $now);
        Fiber::suspend($identity);
        $write($identity);
    });
    $identity = $request->start();
    check($request->isSuspended() && $identity instanceof SessionIdentity,
        'Write did not reach the authenticated boundary');
    $revoke($identity);
    try {
        $request->resume();
    } catch (BusinessException $exception) {
        check($exception->code() === 'token_invalid', 'Write with a revoked identity did not return token_invalid');

        return;
    }
    check(false, 'Write succeeded with a revoked identity');
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
$resetPassword = static function (int $accountId) use ($reset, $newPassword, $now): void {
    $reset->withLockedAccount($accountId,
        static fn () => $reset->reset($accountId, Password::hash($newPassword), $now));
};
assertRevokedWrite($sessions, $resetAccount, $now, $setPassword,
    static fn (SessionIdentity $_identity) => $resetPassword($resetAccount));
$email->login($resetAddress, $newPassword, SessionService::PURPOSE_ORGANIZATION, $now);
echo "PASS password reset rejects a previously authenticated password write and preserves the reset password\n";

$recoveryAddress = EmailAddress::fromInput('recovery-write@example.test');
$recoveryAccount = $seed('+8613800138012', $recoveryAddress->value);
$emailRecovery = new PhoneChangeFlow(
    bin2hex(random_bytes(16)), $recoveryAccount, MainlandMobileNumber::fromInput('+8613800138012'),
    $phoneChange->account($recoveryAccount)['phoneEpoch'], MainlandMobileNumber::fromInput('+8613900139012'),
    'email_recovery', $now + 600, $now + 600, $recoveryAccount,
);
assertRevokedWrite($sessions, $recoveryAccount, $now, $setPassword, static fn (SessionIdentity $_identity) => $recover($emailRecovery));
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
assertRevokedWrite($sessions, $bindingAccount, $now, $bind, static fn (SessionIdentity $_identity) => $recover($manualRecovery));
check($accounts->emailOwner($unbound) === null, 'Revoked request bound an email');
$freshSession = $sessions->issue($bindingAccount, SessionService::PURPOSE_ORGANIZATION, $now);
$bind($sessions->authenticate($freshSession['access_token'], SessionService::PURPOSE_ORGANIZATION, $now));
check($accounts->emailOwner($unbound) === $bindingAccount, 'Rejected binding consumed the proof');
echo "PASS phone recovery rejects a previously authenticated email binding without consuming the proof\n";

$logoutAddress = EmailAddress::fromInput('logout-write@example.test');
$logoutAccount = $seed('+8613800138014', $logoutAddress->value);
assertRevokedWrite($sessions, $logoutAccount, $now, $setPassword, $sessions->logout(...));
$email->login($logoutAddress, $password, SessionService::PURPOSE_ORGANIZATION, $now);
echo "PASS logout rejects a previously authenticated password write and preserves the existing password\n";

$platformPurpose = SessionService::PURPOSE_PLATFORM;
$operatorSession = $sessions->issue($operatorId, $platformPurpose, $now);
$operator = $sessions->authenticate($operatorSession['access_token'], $platformPurpose, $now);
$platformAdmins = new ManagePlatformAdminsService(
    new IlluminatePlatformAdminMutationRepository($revocationDb),
    $sessions,
    static fn (): int => $now,
);
$sessionAccounts = new IlluminateSessionAccountRepository($revocationDb);
$platformPhone = '+8613800138015';
$platformAddress = EmailAddress::fromInput('platform-write@example.test');
$platformAccount = $seed($platformPhone, $platformAddress->value);
check($platformAdmins->grant($operator, $platformPhone) === $platformAccount, 'Initial platform grant targeted the wrong account');
assertRevokedWrite(
    $sessions,
    $platformAccount,
    $now,
    $setPassword,
    static function (SessionIdentity $identity) use ($platformAdmins, $sessionAccounts, $operator, $platformPhone): void {
        $accountId = $identity->accountId;
        $platformAdmins->revoke($operator, $accountId);
        $revoked = $sessionAccounts->currentState($accountId, true);
        check($revoked !== null && $revoked['platformEpoch'] === null, 'Platform qualification was not revoked');
        check($platformAdmins->grant($operator, $platformPhone) === $accountId, 'Platform regrant targeted the wrong account');
        $regranted = $sessionAccounts->currentState($accountId, true);
        check($regranted !== null && $regranted['accountEpoch'] === $identity->accountEpoch,
            'Platform regrant fixture changed the account epoch');
        check($regranted['platformEpoch'] !== null && $regranted['platformEpoch'] !== $identity->platformEpoch,
            'Platform regrant did not restore qualification with a new epoch');
    },
    $platformPurpose,
);
$email->login($platformAddress, $password, $platformPurpose, $now);
echo "PASS platform revoke and regrant reject a previously authenticated password write and preserve the existing password\n";

$profileAddress = EmailAddress::fromInput('profile-reset-write@example.test');
$profileAccount = $seed('+8613800138016', $profileAddress->value);
$profiles = new ProfileService(new IlluminateProfileRepository($requestDb), $sessions);
$profileSession = $sessions->issue($profileAccount, SessionService::PURPOSE_ORGANIZATION, $now);
$profileIdentity = $sessions->authenticate($profileSession['access_token'], SessionService::PURPOSE_ORGANIZATION, $now);
$profiles->update($profileIdentity, 'Existing name', $now);
assertRevokedWrite(
    $sessions,
    $profileAccount,
    $now,
    static function (SessionIdentity $identity) use ($profiles, $now): void {
        $profiles->update($identity, 'Deferred name', $now);
    },
    static fn (SessionIdentity $_identity) => $resetPassword($profileAccount),
);
check($profiles->get($profileAccount)['display_name'] === 'Existing name', 'Rejected profile write changed the display name');
echo "PASS password reset rejects a previously authenticated profile update and preserves the existing display name\n";

require __DIR__ . '/credential-write-lock.php';
$lockAddress = EmailAddress::fromInput('locked-write@example.test');
$lockAccount = $seed('+8613800138017', $lockAddress->value);
assertCredentialWriteHoldsAccountLock($requestDb, $revocationDb, $sessions, $email, $reset, $lockAccount, $lockAddress, $now);
