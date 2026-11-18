<?php

declare(strict_types=1);

namespace StarterAcceptance;

use PDO;

const OPERATOR_RECOVERY = '/saas/platform/phone-recovery';
const OPERATOR_NEW_PHONE = '13900139111';

function operatorRecoveryRequest(
    int $port,
    ?string $access,
    string $step = '',
    array $data = [],
    int $status = 200,
    string $code = 'success',
): array {
    return smsExpect(smsRequest($port, 'POST', OPERATOR_RECOVERY . $step, $data,
        $access === null ? [] : sessionBearer($access)), $status, $code, 'Operator recovery ' . $step)['data'] ?? [];
}

function operatorRecoveryFixture(Fixtures $fixtures): void
{
    $config = "$fixtures->host/config/starter.php";
    $source = file_get_contents($config);
    check($source !== false, 'Could not read the disposable SMS fixture config');
    check(file_put_contents("$fixtures->host/config/starter.operator-base.inc", $source) !== false,
        'Could not preserve the disposable SMS fixture config');
    check(file_put_contents($config, <<<'PHP'
        <?php

        declare(strict_types=1);

        use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
        use Z0x\WebmanStarter\saas\operator\OperatorRecoveryEvidence;
        use Z0x\WebmanStarter\saas\operator\OperatorRecoveryEvidenceVerifierInterface;

        $settings = require __DIR__ . '/starter.operator-base.inc';
        $path = getenv('QA_OPERATOR_RECOVERY_CASE');
        if ($settings === [] || $path === false || $path === '') {
            return $settings;
        }
        $settings['operator_recovery_verifier'] = new class ($path) implements OperatorRecoveryEvidenceVerifierInterface {
            public function __construct(private readonly string $path)
            {
            }

            public function verify(OperatorRecoveryEvidence $evidence, int $accountId, MainlandMobileNumber $newPhone, int|string $actor): bool
            {
                $approved = json_decode(file_get_contents($this->path), true, flags: JSON_THROW_ON_ERROR);

                return $approved === [
                    'channel' => $evidence->channel, 'reference' => $evidence->reference, 'reason' => $evidence->reason,
                    'account_id' => $accountId, 'new_phone' => $newPhone->e164, 'actor' => $actor,
                ];
            }
        };

        return $settings;
        PHP) !== false, 'Could not install the disposable recovery verifier');
}

function approveOperatorRecovery(string $path, int $account, int|string $actor, string $channel = 'registered_contact'): void
{
    check(file_put_contents($path, json_encode([
        'channel' => $channel, 'reference' => 'CASE-ALPHA', 'reason' => 'lost_phone',
        'account_id' => $account, 'new_phone' => '+86' . OPERATOR_NEW_PHONE, 'actor' => $actor,
    ], JSON_THROW_ON_ERROR)) !== false, 'Could not set the fixture case approval');
}

function testOperatorRecovery(Fixtures $fixtures, SmsLoginFixture $qa): void
{
    operatorRecoveryFixture($fixtures);
    $db = $fixtures->database('qa_manualrecovery');
    migrate($fixtures);
    cliResult(init($fixtures, PHONE)->result(), true, 'platform_admin_initialized');
    $actor = (int) $db->query("SELECT account_id FROM starter_platform_admins WHERE status = 'active'")->fetchColumn();
    $account = rebindAccount($db, SMS_MEMBER);
    rebindInvitations($db, $account, SMS_MEMBER);
    $case = "$fixtures->dir/operator-recovery-case";
    approveOperatorRecovery($case, $account, $actor);
    $qa->clock(SMS_TIME);
    $qa->outcome('accepted');
    $qa->withHost('manual-recovery', static function (int $port) use ($fixtures, $qa, $db, $actor, $account): void {
        $access = smsToken(smsLogin($port, $qa->issue($port)));
        $target = sessionTokens(smsLogin($port, $qa->issue($port, SMS_MEMBER), phone: SMS_MEMBER, purpose: 'organization'));
        $input = [
            'account_id' => $account, 'new_phone' => OPERATOR_NEW_PHONE, 'reason' => 'lost_phone',
            'evidence' => ['channel' => 'registered_contact', 'reference' => 'CASE-ALPHA'],
        ];
        operatorRecoveryRequest($port, null, data: $input, status: 401, code: 'unauthenticated');
        operatorRecoveryRequest($port, $target['access_token'], data: $input, status: 403, code: 'token_purpose_mismatch');
        operatorRecoveryRequest($port, $access, data: array_replace($input, ['account_id' => $actor]),
            status: 403, code: 'forbidden');
        operatorRecoveryRequest($port, $access, data: array_replace($input, ['evidence' => 'CASE-ALPHA']),
            status: 422, code: 'validation_failed');
        operatorRecoveryRequest($port, $access, data: array_replace_recursive($input, ['evidence' => ['reference' => 'CASE-UNCHECKED']]),
            status: 403, code: 'forbidden');
        operatorRecoveryRequest($port, $access, data: array_replace($input, ['new_phone' => REBIND_NEW]),
            status: 403, code: 'forbidden');
        $email = $db->prepare('UPDATE starter_accounts SET email = ?, email_verified_at = ? WHERE id = ?');
        $email->execute(['manual-recovery@example.test', gmdate('Y-m-d H:i:s', SMS_TIME), $account]);
        operatorRecoveryRequest($port, $access, data: $input, status: 403, code: 'forbidden');
        $email->execute([null, null, $account]);
        $started = operatorRecoveryRequest($port, $access, data: $input, status: 201);
        check(preg_match('/\A[0-9a-f]{32}\z/', $started['flow_id'] ?? '') === 1, 'Manual recovery did not return a flow');
        $flow = '/' . $started['flow_id'];
        operatorRecoveryRequest($port, $access, "$flow/complete", status: 401, code: 'credential_expired');
        $before = count($qa->messages());
        rejectConcurrentOperatorEpochChange($fixtures, $db, $port, $access, $actor, $flow);
        check(count($qa->messages()) === $before, 'A stale operator session reached the SMS sender');
        $qa->clock(SMS_TIME + 60);
        $access = smsToken(smsLogin($port, $qa->issue($port)));
        $before = count($qa->messages());
        $challenge = operatorRecoveryRequest($port, $access, "$flow/new/send")['challenge_id'];
        $messages = $qa->messages();
        check(count($messages) === $before + 1 && $messages[$before]['phone'] === '+86' . OPERATOR_NEW_PHONE
            && $messages[$before]['purpose'] === 'rebind', 'Manual recovery did not send a new-phone rebind SMS');
        $proof = ['challenge_id' => $challenge, 'code' => $messages[$before]['code']];
        operatorRecoveryRequest($port, $access, "$flow/new/verify", array_replace($proof, ['code' => wrongSmsCode($proof['code'])]),
            401, 'verification_code_invalid');
        check(operatorRecoveryRequest($port, $access, "$flow/new/verify", $proof) === [
            'verified_until' => gmdate(DATE_ATOM, SMS_TIME + 660),
        ], 'Manual recovery did not return the short-lived proof deadline in UTC');
        $db->exec("CREATE TRIGGER qa_recovery_audit_failure BEFORE INSERT ON starter_audit_logs FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced recovery audit failure'");
        $before = snapshot($db);
        try {
            operatorRecoveryRequest($port, $access, "$flow/complete", status: 503, code: 'audit_unavailable');
            check(snapshot($db) === $before, 'Manual recovery kept a partial change after audit failure');
        } finally {
            $db->exec('DROP TRIGGER qa_recovery_audit_failure');
        }
        check(operatorRecoveryRequest($port, $access, "$flow/complete") === ['changed' => true], 'Manual recovery did not complete');
        operatorRecoveryRequest($port, $access, "$flow/complete", status: 404, code: 'not_found');
        sessionRefresh($port, $target['refresh_token'], 401, 'token_invalid');
        $fresh = smsToken(smsLogin($port, $qa->issue($port, OPERATOR_NEW_PHONE), phone: OPERATOR_NEW_PHONE, purpose: 'organization'));
        smsExpect(smsRequest($port, 'GET', '/saas/account/profile', headers: sessionBearer($fresh)), 200, 'success', 'Recovered account login');
        assertOperatorRecoveryAudit($db, $account, $actor, 'manual_recovery', 'registered_contact', SMS_MEMBER);
    }, ['QA_OPERATOR_RECOVERY_CASE' => $case]);
    echo "PASS manual recovery requires platform authorization, checked evidence, fresh SMS and transactional audit\n";

    testOfflineOperatorRecovery($fixtures, $qa, $case);
}

function rejectConcurrentOperatorEpochChange(
    Fixtures $fixtures,
    PDO $db,
    int $port,
    string $access,
    int $actor,
    string $flow,
): void {
    $client = null;
    $db->beginTransaction();
    try {
        $db->query("SELECT session_epoch FROM starter_accounts WHERE id = $actor FOR UPDATE")->closeCursor();
        clearstatcache(true, $fixtures->sqlLog);
        $offset = filesize($fixtures->sqlLog);
        check($offset !== false, 'Could not locate the operator recovery lock in the query log');
        $client = smsConnect($port, 'POST', OPERATOR_RECOVERY . "$flow/new/send", [], sessionBearer($access));
        $deadline = microtime(true) + 3;
        do {
            $queryLog = file_get_contents($fixtures->sqlLog, false, null, $offset);
            check($queryLog !== false, 'Could not read the operator recovery query log');
            if (preg_match('/from `starter_accounts` where `id` = ' . $actor . ' limit 1 for update/i', $queryLog) === 1) {
                break;
            }
            check(microtime(true) < $deadline, 'Recovery did not acquire the operator account lock after middleware');
            usleep(1_000);
        } while (true);
        $db->exec("UPDATE starter_accounts SET session_epoch = session_epoch + 1 WHERE id = $actor");
        $db->commit();
        smsExpect(smsReadResponse($client), 401, 'token_invalid', 'Recovery after concurrent operator session revocation');
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if (is_resource($client)) {
            fclose($client);
        }
    }
}

function testOfflineOperatorRecovery(Fixtures $fixtures, SmsLoginFixture $qa, string $case): void
{
    $db = $fixtures->database('qa_offlinerecovery');
    migrate($fixtures);
    cliResult(init($fixtures, PHONE)->result(), true, 'platform_admin_initialized');
    $account = (int) $db->query("SELECT account_id FROM starter_platform_admins WHERE status = 'active'")->fetchColumn();
    rebindInvitations($db, $account, PHONE);
    $actor = 'uid:' . posix_geteuid();
    approveOperatorRecovery($case, $account, $actor, 'authorized_channel');
    $qa->clock(SMS_TIME);
    $env = ['QA_OPERATOR_RECOVERY_CASE' => $case, 'STARTER_OPERATOR_RECOVERY_CLI_ENABLED' => '1'];
    $start = static function (array $override = [], string $reference = 'CASE-ALPHA', bool $pipe = false) use ($fixtures, $qa, $env): Process {
        return $fixtures->start([
            PHP_BINARY, 'vendor/bin/webman-starter', 'platform:recover', OPERATOR_NEW_PHONE, 'lost_phone', 'authorized_channel', $reference,
        ], $fixtures->host, $override + $env + [
            'STARTER_AUTH_ENABLED' => '1', 'STARTER_AUTH_SECRET' => str_repeat('qa01-sms-fixture-', 3),
            'STARTER_REDIS_PREFIX' => 'qa01:offline-recovery', 'QA_SMS_STATE' => $qa->stateDirectory(),
        ], pipeInput: $pipe);
    };
    $qa->withHost('offline-recovery', static function (int $port) use ($fixtures, $qa, $db, $start, $account, $actor): void {
        $target = sessionTokens(smsLogin($port, $qa->issue($port)));
        $before = snapshot($db);
        $messages = count($qa->messages());
        cliResult($start(['STARTER_OPERATOR_RECOVERY_CLI_ENABLED' => '0'])->result(), false, 'offline_recovery_disabled');
        cliResult($start(['QA_OPERATOR_RECOVERY_CASE' => ''])->result(), false, 'offline_recovery_disabled');
        cliResult($start(['QA_OPERATOR_RECOVERY_CASE' => $fixtures->dir . '/missing-recovery-case'])->result(), false,
            'recovery_evidence_unverified');
        cliResult($start(reference: 'CASE-UNCHECKED')->result(), false, 'recovery_evidence_unverified');
        cliResult($start(reference: '')->result(), false, 'validation_failed');
        cliResult(init($fixtures, OPERATOR_NEW_PHONE)->result(), false, 'platform_admin_already_initialized');
        check(snapshot($db) === $before && count($qa->messages()) === $messages, 'Rejected CLI recovery persisted data or sent an SMS');

        $email = $db->prepare('UPDATE starter_accounts SET email = ?, email_verified_at = ? WHERE id = ?');
        $email->execute(['recovery@example.test', gmdate('Y-m-d H:i:s', SMS_TIME), $account]);
        cliResult($start()->result(), false, 'verified_email_exists');
        $email->execute([null, null, $account]);

        $second = rebindAccount($db, SMS_MEMBER);
        $add = $db->prepare("INSERT INTO starter_platform_admins (account_id, status, created_at, updated_at) VALUES (?, 'active', NOW(), NOW())");
        $add->execute([$second]);
        cliResult($start()->result(), false, 'offline_recovery_not_eligible');
        $db->exec("DELETE FROM starter_platform_admins WHERE account_id = $second");
        check(count($qa->messages()) === $messages, 'An ineligible offline recovery reached the SMS sender');

        $first = $start(pipe: true);
        $sentCode = awaitOfflineRecoverySms($fixtures, $qa, $first);
        $first->writeInput(wrongSmsCode($sentCode) . "\n");
        cliResult($first->result(), false, 'verification_code_invalid');
        check($db->query("SELECT phone FROM starter_accounts WHERE id = $account")->fetchColumn() === '+86' . PHONE,
            'A wrong CLI SMS code changed the account');

        testOfflineRecoveryTightenedAttempts($fixtures, $qa, $db, $start);
        sessionPlatform($port, $target['access_token']);

        $qa->clock(SMS_TIME + 60);
        $ineligible = $start(pipe: true);
        $sentCode = awaitOfflineRecoverySms($fixtures, $qa, $ineligible);
        $add->execute([$second]);
        $ineligible->writeInput($sentCode . "\n");
        cliResult($ineligible->result(), false, 'offline_recovery_not_eligible');
        $db->exec("DELETE FROM starter_platform_admins WHERE account_id = $second");

        $qa->clock(SMS_TIME + 120);
        $ineligible = $start(pipe: true);
        $sentCode = awaitOfflineRecoverySms($fixtures, $qa, $ineligible);
        $email->execute(['recovery@example.test', gmdate('Y-m-d H:i:s', SMS_TIME), $account]);
        $ineligible->writeInput($sentCode . "\n");
        cliResult($ineligible->result(), false, 'verified_email_exists');
        $email->execute([null, null, $account]);

        $qa->clock(SMS_TIME + 180);
        $failing = $start(pipe: true);
        $sentCode = awaitOfflineRecoverySms($fixtures, $qa, $failing);
        $db->exec("CREATE TRIGGER qa_recovery_audit_failure BEFORE INSERT ON starter_audit_logs FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced offline audit failure'");
        try {
            $failing->writeInput($sentCode . "\n");
            cliResult($failing->result(), false, 'audit_unavailable');
            check($db->query("SELECT phone FROM starter_accounts WHERE id = $account")->fetchColumn() === '+86' . PHONE
                && (int) $db->query("SELECT COUNT(*) FROM starter_invitations WHERE phone = '+86" . PHONE . "' AND status = 'pending' AND reserved = 1")->fetchColumn() === 2,
                'Offline audit failure left a partially recovered account');
            sessionPlatform($port, $target['access_token']);
        } finally {
            $db->exec('DROP TRIGGER qa_recovery_audit_failure');
        }

        $qa->clock(SMS_TIME + 240);
        $success = $start(pipe: true);
        $sentCode = awaitOfflineRecoverySms($fixtures, $qa, $success);
        $success->writeInput($sentCode . "\n");
        $result = $success->result();
        cliResult($result, true, 'platform_admin_recovered');
        check(!str_contains($result[1], $sentCode), 'CLI exposed the incoming SMS code');
        sessionPlatform($port, $target['access_token'], 401, 'token_invalid');
        sessionRefresh($port, $target['refresh_token'], 401, 'token_invalid');
        $fresh = smsToken(smsLogin($port, $qa->issue($port, OPERATOR_NEW_PHONE), phone: OPERATOR_NEW_PHONE));
        $me = smsExpect(smsRequest($port, 'GET', PLATFORM_ME, headers: sessionBearer($fresh)), 200, 'success', 'Offline recovered platform login');
        check($me['data']['account_id'] === $account, 'Offline recovery did not preserve the active platform qualification');
        assertOperatorRecoveryAudit($db, $account, $actor, 'offline_recovery', 'authorized_channel', PHONE);
    }, $env);
    echo "PASS offline recovery enforces CLI opt-in and current sole-admin eligibility through real SMS input and audit\n";
}

/** @param callable(array<string,string>, string, bool): Process $start */
function testOfflineRecoveryTightenedAttempts(Fixtures $fixtures, SmsLoginFixture $qa, PDO $db, callable $start): void
{
    // The CLI reads one code per invocation. Seed earlier failures on each live challenge before writing stdin.
    foreach ([1 => 'verification_attempts_exceeded', 2 => 'credential_expired'] as $priorFailures => $expected) {
        $prefix = 'qa01:offline-attempts-' . $priorFailures;
        $cli = $start(['STARTER_SMS_ATTEMPTS' => '2', 'STARTER_REDIS_PREFIX' => $prefix], pipe: true);
        try {
            $code = awaitOfflineRecoverySms($fixtures, $qa, $cli);
            $seed = $fixtures->start([PHP_BINARY, '-r', <<<'PHP'
                $redis = new Redis();
                if (!$redis->connect(getenv('STARTER_REDIS_HOST'), (int) getenv('STARTER_REDIS_PORT'))
                    || !$redis->auth(getenv('STARTER_REDIS_PASSWORD'))) {
                    fwrite(STDERR, "Could not connect to the isolated Redis fixture\n");
                    exit(1);
                }
                $keys = $redis->keys($argv[1] . ':verification:v1:otp-id:*');
                if (count($keys) !== 1 || $redis->hGet($keys[0], 'failures') !== '0'
                    || $redis->hGet($keys[0], 'maxAttempts') !== '2'
                    || $redis->hSet($keys[0], 'failures', $argv[2]) !== 0) {
                    fwrite(STDERR, "Expected one fresh offline challenge with a two-attempt limit\n");
                    exit(1);
                }
                PHP, $prefix, (string) $priorFailures], $fixtures->host);
            [$exit, $output] = $seed->result();
            check($exit === 0, "Could not prepare offline recovery attempt budget: $output");

            $before = snapshot($db);
            $cli->writeInput(($priorFailures === 1 ? wrongSmsCode($code) : $code) . "\n");
            cliResult($cli->result(), false, $expected);
            $after = snapshot($db);
            // Verification failures can append audit events. Recovery state must stay intact.
            unset($before['starter_audit_logs'], $after['starter_audit_logs']);
            check($after === $before, "Offline recovery changed persisted state after $expected");
        } finally {
            $cli->stop();
        }
    }
    echo "PASS AC-09 offline recovery honors the two-attempt SMS limit and preserves persisted state\n";
}

function awaitOfflineRecoverySms(Fixtures $fixtures, SmsLoginFixture $qa, Process $process): string
{
    $fixtures->ready($process, static fn (): bool => str_contains($process->output(), 'Enter the six-digit SMS code on stdin.'),
        'Offline CLI SMS prompt');
    $messages = $qa->messages();
    $sms = end($messages);
    check($sms !== false && $sms['phone'] === '+86' . OPERATOR_NEW_PHONE && $sms['purpose'] === 'rebind'
        && preg_match('/\A[0-9]{6}\z/', $sms['code']) === 1, 'Offline CLI did not submit a real new-phone SMS');

    return $sms['code'];
}

function assertOperatorRecoveryAudit(PDO $db, int $account, int|string $actor, string $mode, string $channel, string $oldPhone): void
{
    $rows = $db->query("SELECT actor_account_id, target_account_id, metadata FROM starter_audit_logs WHERE action = 'phone_changed'")
        ->fetchAll(PDO::FETCH_ASSOC);
    check(count($rows) === 1 && (int) $rows[0]['target_account_id'] === $account, 'Expected one recovery audit for the original account');
    $audit = json_decode($rows[0]['metadata'], true, flags: JSON_THROW_ON_ERROR);
    $expected = [
        'method' => $mode, 'old_phone_masked' => '**********' . substr($oldPhone, -4), 'new_phone_masked' => '**********9111',
        'result' => 'success', 'reason' => 'lost_phone', 'evidence_channel' => $channel, 'evidence_ref' => 'CASE-ALPHA',
    ];
    if (is_string($actor)) {
        $expected['actor_ref'] = $actor;
        check($rows[0]['actor_account_id'] === null, 'Offline recovery used an account as the operator');
    } else {
        check((int) $rows[0]['actor_account_id'] === $actor, 'Manual recovery audit has the wrong platform operator');
    }
    check($audit == $expected, 'Expected only approved reason, opaque evidence reference and redacted audit metadata');
    check($db->query("SELECT phone FROM starter_accounts WHERE id = $account")->fetchColumn() === '+86' . OPERATOR_NEW_PHONE,
        'Recovery did not preserve the original account ID');
    check((int) $db->query("SELECT COUNT(*) FROM starter_invitations WHERE phone = '+86$oldPhone' AND status = 'pending'")->fetchColumn() === 0,
        'Recovery did not revoke old-phone invitations');
    check($db->query("SELECT role FROM starter_memberships WHERE account_id = $account")->fetchColumn() === 'owner',
        'Recovery changed the existing membership');
}
