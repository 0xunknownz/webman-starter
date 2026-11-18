<?php

declare(strict_types=1);

namespace StarterAcceptance;

use PDO;

/** @param array<string,mixed>|null $data */
function assertMutationRejectedAfterLogout(
    Fixtures $fixtures,
    PDO $db,
    int $port,
    string $token,
    string $method,
    string $path,
    string $label,
    ?array $data = null,
    ?int $accountId = null,
): void {
    // A rolled-back grant may allocate an auto-increment ID. Compare persisted rows, not table metadata.
    $rows = static fn (): array => array_map(static fn (array $table): array => $table[1], snapshot($db));
    $before = $rows();
    $table = $accountId === null ? 'starter_platform_guard' : 'starter_accounts';
    $client = null;
    $db->beginTransaction();
    try {
        $lock = $db->prepare("SELECT id FROM $table WHERE id = ? FOR UPDATE");
        $lock->execute([$accountId ?? 1]);
        $lock->closeCursor();
        clearstatcache(true, $fixtures->sqlLog);
        $offset = filesize($fixtures->sqlLog);
        check($offset !== false, 'Could not locate the mutation in the MySQL query log');
        $client = smsConnect($port, $method, $path, $data, sessionBearer($token));

        // The route acquires this lock only after authenticating the request.
        $deadline = microtime(true) + 3;
        $lockingQuery = '/\b(?:Query|Execute)\s+select [^\r\n]*from \\x60' . $table . '\\x60[^\r\n]*for update/i';
        do {
            $log = file_get_contents($fixtures->sqlLog, false, null, $offset);
            check($log !== false, 'Could not read the mutation in the MySQL query log');
            if (preg_match($lockingQuery, $log) === 1) {
                break;
            }
            check(microtime(true) < $deadline, "$label did not reach the held lock after authentication");
            usleep(1_000);
        } while (true);

        sessionLogout($port, $token);
        $db->rollBack();
        $body = smsExpect(smsReadResponse($client), 401, 'token_invalid', "$label after concurrent logout");
        check($body['data'] === null, "$label returned data with a revoked identity");
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if (is_resource($client)) {
            fclose($client);
        }
    }
    check($rows() === $before, "$label modified persisted rows after logout");
    echo "PASS $label rejects concurrent logout after authentication and preserves persisted rows\n";
}

function testMutationsAfterConcurrentLogout(Fixtures $fixtures, SmsLoginFixture $qa): void
{
    $db = $fixtures->database('qa_mutationlogout');
    migrate($fixtures);
    cliResult(init($fixtures, PHONE)->result(), true, 'platform_admin_initialized');
    $actor = (int) $db->query("SELECT account_id FROM starter_platform_admins WHERE status = 'active'")->fetchColumn();
    $phoneAccount = rebindAccount($db, SMS_MEMBER);
    $emailAccount = rebindAccount($db, OTHER_PHONE);
    $recoveryPhone = '13800138701';
    $recoveryAccount = rebindAccount($db, $recoveryPhone);
    rebindInvitations($db, $phoneAccount, SMS_MEMBER);
    rebindInvitations($db, $recoveryAccount, $recoveryPhone);
    $case = "$fixtures->dir/operator-logout-case";
    approveOperatorRecovery($case, $recoveryAccount, $actor);
    $qa->outcome('accepted');
    $qa->withHost('mutation-logout-race', static function (int $port) use (
        $fixtures, $qa, $db, $phoneAccount, $emailAccount, $recoveryAccount,
    ): void {
        $qa->clock(SMS_TIME);
        $phoneToken = smsToken(smsLogin($port, $qa->issue($port, SMS_MEMBER), phone: SMS_MEMBER, purpose: 'organization'));
        $emailToken = smsToken(smsLogin($port, $qa->issue($port, OTHER_PHONE), phone: OTHER_PHONE, purpose: 'organization'));
        $qa->clock(SMS_TIME + 60);
        $phoneFlow = rebindProof($qa, $port, $phoneToken, SMS_MEMBER, REBIND_NEW);
        assertMutationRejectedAfterLogout($fixtures, $db, $port, $phoneToken, 'POST', REBIND_PATH . "$phoneFlow/complete",
            'Phone change completion', accountId: $phoneAccount);

        $oldEmail = 'before-logout@example.test';
        emailBind($port, $emailToken, $oldEmail, emailChallenge($qa, $port, $emailToken, $oldEmail));
        $qa->clock(SMS_TIME + 120);
        $emailFlow = prepareEmailChange($qa, $port, $emailToken, $oldEmail, 'after-logout@example.test');
        assertMutationRejectedAfterLogout($fixtures, $db, $port, $emailToken, 'POST', EMAIL_CHANGE . "$emailFlow/complete",
            'Email change completion', accountId: $emailAccount);

        $operatorLogin = static function (int $offset) use ($qa, $port): string {
            $qa->clock(SMS_TIME + $offset);

            return smsToken(smsLogin($port, $qa->issue($port)));
        };
        $operator = $operatorLogin(180);
        $started = operatorRecoveryRequest($port, $operator, data: [
            'account_id' => $recoveryAccount, 'new_phone' => OPERATOR_NEW_PHONE, 'reason' => 'lost_phone',
            'evidence' => ['channel' => 'registered_contact', 'reference' => 'CASE-ALPHA'],
        ]);
        $flow = '/' . $started['flow_id'];
        $beforeSms = count($qa->messages());
        $challenge = operatorRecoveryRequest($port, $operator, "$flow/new/send")['challenge_id'];
        $messages = $qa->messages();
        check(count($messages) === $beforeSms + 1 && $messages[$beforeSms]['phone'] === '+86' . OPERATOR_NEW_PHONE
            && $messages[$beforeSms]['purpose'] === 'rebind', 'Expected an SMS for the manual recovery destination');
        operatorRecoveryRequest($port, $operator, "$flow/new/verify", ['challenge_id' => $challenge, 'code' => $messages[$beforeSms]['code']]);
        assertMutationRejectedAfterLogout($fixtures, $db, $port, $operator, 'POST', OPERATOR_RECOVERY . "$flow/complete",
            'Manual recovery completion');

        $operator = $operatorLogin(240);
        $revokeTarget = platformGrant($port, $operator, '13900138702');
        assertMutationRejectedAfterLogout($fixtures, $db, $port, $operator, 'POST', PLATFORM_ADMINS,
            'Platform grant', ['phone' => '13900138703']);
        $operator = $operatorLogin(300);
        assertMutationRejectedAfterLogout($fixtures, $db, $port, $operator, 'DELETE', PLATFORM_ADMINS . '/' . $revokeTarget,
            'Platform revoke');
    }, ['QA_OPERATOR_RECOVERY_CASE' => $case]);
}
