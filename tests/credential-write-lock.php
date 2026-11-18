<?php

declare(strict_types=1);

namespace StarterAcceptance;

use Fiber;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Z0x\WebmanStarter\saas\email\EmailAddress;
use Z0x\WebmanStarter\saas\email\EmailSignInService;
use Z0x\WebmanStarter\saas\email\Password;
use Z0x\WebmanStarter\saas\reset\IlluminatePasswordResetRepository;
use Z0x\WebmanStarter\saas\session\SessionService;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

function assertCredentialWriteHoldsAccountLock(
    Connection $requestDb,
    Connection $revocationDb,
    SessionService $sessions,
    EmailSignInService $email,
    IlluminatePasswordResetRepository $reset,
    int $accountId,
    EmailAddress $address,
    int $now,
): void {
    $purpose = SessionService::PURPOSE_ORGANIZATION;
    $credentials = $sessions->issue($accountId, $purpose, $now);
    $identity = $sessions->authenticate($credentials['access_token'], $purpose, $now);
    $pause = true;
    $requestDb->beforeExecuting(static function (string $sql) use (&$pause): void {
        if ($pause && str_starts_with(strtolower($sql), 'update ')
            && str_contains($sql, 'starter_accounts') && str_contains($sql, 'password_hash')) {
            $pause = false;
            // At this point the service has revalidated the session. No UPDATE lock has been acquired yet.
            Fiber::suspend();
        }
    });
    $writtenPassword = 'qa-ordered-write-password';
    $resetPassword = 'qa-ordered-reset-password';
    $writer = new Fiber(static fn () => $email->setPassword($identity, $writtenPassword, $now));
    $writer->start();
    check($writer->isSuspended() && !$pause, 'Password write did not reach its pre-UPDATE barrier');
    try {
        try {
            $revocationDb->transaction(static fn () => $revocationDb->table('starter_accounts')->where('id', $accountId)
                ->lock('for update nowait')->first(['id']));
            check(false, 'Revocation acquired the account lock before the password write committed');
        } catch (QueryException $exception) {
            // MySQL ER_LOCK_NOWAIT verifies real contention without relying on a timeout.
            check((int) ($exception->errorInfo[1] ?? 0) === 3572, 'Expected account row contention');
        }
    } finally {
        $writer->resume();
    }
    check($writer->isTerminated(), 'Password write did not finish');
    $email->login($address, $writtenPassword, $purpose, $now);
    $reset->withLockedAccount($accountId, static fn () => $reset->reset($accountId, Password::hash($resetPassword), $now));
    $email->login($address, $resetPassword, $purpose, $now);
    try {
        $sessions->authenticate($credentials['access_token'], $purpose, $now);
        check(false, 'Password reset did not revoke the session after the locked write');
    } catch (BusinessException $exception) {
        check($exception->code() === 'token_invalid', 'Unexpected session state after ordered password reset');
    }
    echo "PASS password write holds the account lock from revalidation through commit and reset follows it\n";
}
