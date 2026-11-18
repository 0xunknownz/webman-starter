<?php

declare(strict_types=1);

namespace StarterAcceptance;

use PDO;

function testTightenedSmsAttempts(Fixtures $fixtures, SmsLoginFixture $qa): void
{
    $db = $fixtures->database('qa_tightenedsms');
    migrate($fixtures);
    cliResult(init($fixtures, PHONE)->result(), true, 'platform_admin_initialized');
    $operator = (int) $db->query("SELECT account_id FROM starter_platform_admins WHERE status = 'active'")->fetchColumn();
    $target = rebindAccount($db, SMS_MEMBER);
    $case = "$fixtures->dir/ac09-recovery-case";
    approveOperatorRecovery($case, $target, $operator);
    $qa->clock(SMS_TIME);
    $qa->outcome('accepted');
    $qa->withHost('ac09-tightened-attempts', static function (int $port) use ($qa, $db, $target): void {
        $access = smsToken(smsLogin($port, $qa->issue($port)));
        emailBind($port, $access, RECOVERY_EMAIL, emailChallenge($qa, $port, $access, RECOVERY_EMAIL));

        [$flow, $source] = rebindStart($qa, $port, $access, PHONE, REBIND_NEW);
        $verifyOld = static fn (array $input, int $status, string $code): array =>
            phoneChangeRequest($port, $access, "$flow/old/verify", $input, $status, $code);
        $before = ac09DatabaseState($db);
        assertAc09SmsExhausted($source, $verifyOld);
        $sent = count($qa->messages());
        phoneChangeRequest($port, $access, "$flow/new/send", status: 401, code: 'credential_expired');
        phoneChangeRequest($port, $access, "$flow/complete", status: 401, code: 'credential_expired');
        check(count($qa->messages()) === $sent && ac09DatabaseState($db) === $before,
            'Exhausted rebind old-phone OTP obtained a proof, sent a destination SMS, or changed persisted state');

        $qa->clock(SMS_TIME + 60);
        $source = rebindIssue($qa, $port, $access, PHONE, "$flow/old/send");
        $verifyOld(array_replace($source, ['code' => wrongSmsCode($source['code'])]), 401, 'verification_code_invalid');
        $verifyOld($source, 200, 'success');
        $verifyOld($source, 401, 'credential_expired');
        $destination = rebindIssue($qa, $port, $access, REBIND_NEW, "$flow/new/send");
        $before = ac09DatabaseState($db);
        assertAc09SmsExhausted($destination, static fn (array $input, int $status, string $code): array =>
            phoneChangeRequest($port, $access, "$flow/new/verify", $input, $status, $code));
        phoneChangeRequest($port, $access, "$flow/complete", status: 401, code: 'credential_expired');
        check(ac09DatabaseState($db) === $before, 'Exhausted rebind new-phone OTP changed persisted state');

        $emailProof = recoveryRequest($port, '/verify', recoveryChallenge($qa, $port) + ['new_phone' => REBIND_OTHER])['data'];
        $flow = $emailProof['flow_id'];
        $token = ['recovery_token' => $emailProof['recovery_token']];
        $destination = recoverySms($qa, $port, $flow, $token, REBIND_OTHER);
        $before = ac09DatabaseState($db);
        assertAc09SmsExhausted($destination, static fn (array $input, int $status, string $code): array =>
            recoveryRequest($port, "/$flow/new/verify", $token + $input, $status, $code));
        recoveryRequest($port, "/$flow/complete", $token, 401, 'credential_expired');
        check(ac09DatabaseState($db) === $before, 'Exhausted email recovery new-phone OTP changed persisted state');

        $started = operatorRecoveryRequest($port, $access, data: [
            'account_id' => $target, 'new_phone' => OPERATOR_NEW_PHONE, 'reason' => 'lost_phone',
            'evidence' => ['channel' => 'registered_contact', 'reference' => 'CASE-ALPHA'],
        ], status: 201);
        check(preg_match('/\A[0-9a-f]{32}\z/', $started['flow_id'] ?? '') === 1, 'Expected an opaque manual recovery flow');
        $flow = '/' . $started['flow_id'];
        $sent = count($qa->messages());
        $challenge = operatorRecoveryRequest($port, $access, "$flow/new/send");
        $messages = $qa->messages();
        check(count($messages) === $sent + 1 && $messages[$sent]['phone'] === '+86' . OPERATOR_NEW_PHONE
            && $messages[$sent]['purpose'] === 'rebind', 'Expected a captured manual recovery SMS to the new phone');
        $destination = ['challenge_id' => $challenge['challenge_id'], 'code' => $messages[$sent]['code']];
        $before = ac09DatabaseState($db);
        assertAc09SmsExhausted($destination, static fn (array $input, int $status, string $code): array =>
            operatorRecoveryRequest($port, $access, "$flow/new/verify", $input, $status, $code));
        operatorRecoveryRequest($port, $access, "$flow/complete", status: 401, code: 'credential_expired');
        check(ac09DatabaseState($db) === $before, 'Exhausted manual recovery new-phone OTP changed persisted state');

        [$flow, $source] = emailChangeIssue($qa, $port, $access, '+86' . PHONE,
            data: ['new_email' => EMAIL_CHANGE_NEW, 'channel' => 'sms'], channel: 'sms');
        $before = ac09DatabaseState($db);
        assertAc09SmsExhausted($source, static fn (array $input, int $status, string $code): array =>
            emailChangeRequest($port, $access, "$flow/old/verify", $input, $status, $code));
        $sent = count(emailMessages($qa));
        emailChangeRequest($port, $access, "$flow/new/send", status: 401, code: 'credential_expired');
        emailChangeRequest($port, $access, "$flow/complete", status: 401, code: 'credential_expired');
        check(count(emailMessages($qa)) === $sent && ac09DatabaseState($db) === $before,
            'Exhausted account-security OTP obtained a proof, sent a destination email, or changed persisted state');
        sessionPlatform($port, $access);
    }, ['STARTER_SMS_ATTEMPTS' => '2', 'QA_OPERATOR_RECOVERY_CASE' => $case]);
    echo "PASS AC-09 two-attempt SMS limit for rebind, email and manual recovery, and email change\n";
}

/** @param array{challenge_id:string,code:string} $challenge @param callable(array, int, string): array $verify */
function assertAc09SmsExhausted(array $challenge, callable $verify): void
{
    $wrong = array_replace($challenge, ['code' => wrongSmsCode($challenge['code'])]);
    $verify($wrong, 401, 'verification_code_invalid');
    $verify($wrong, 401, 'verification_attempts_exceeded');
    $verify($challenge, 401, 'credential_expired');
}

/** @return array<string,mixed> */
function ac09DatabaseState(PDO $db): array
{
    $state = snapshot($db);
    // Rejected verification attempts may append audit events without changing the account or flow.
    unset($state['starter_audit_logs']);

    return $state;
}
