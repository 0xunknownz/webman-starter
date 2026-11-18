<?php

declare(strict_types=1);

namespace StarterAcceptance;

use PDO;

require __DIR__ . '/d2-fixtures.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Z0x\WebmanStarter\saas\invitation\InvitationLink;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

$page = 'https://product.example.test/join?locale=zh';
$links = new InvitationLink($page, str_repeat('K', 32));
$selector = str_repeat('e', 32);
$link = $links->forSelector($selector);
check(str_starts_with($link, $page . '#token='), 'The invitation credential must use the page fragment');
$token = substr((string) parse_url($link, PHP_URL_FRAGMENT), strlen('token='));
check($links->selector($token) === $selector, 'The signed selector must round trip');
check($links->forSelector($selector) === $link, 'Keep must reconstruct the same link');
try {
    $links->selector(substr($token, 0, -1) . ($token[-1] === 'a' ? 'b' : 'a'));
    check(false, 'A modified signature must not be recognized');
} catch (BusinessException $exception) {
    check($exception->code() === 'not_found', 'A modified signature must appear unknown');
}

/** @return array<string, int> */
function seedIssue18(PDO $sql, int $now): array
{
    $date = gmdate('Y-m-d H:i:s', $now);
    $account = $sql->prepare('INSERT INTO starter_accounts (phone, created_at, updated_at) VALUES (?, ?, ?)');
    $ids = [];
    foreach (['owner_a' => '13800138000', 'admin' => '13800138001', 'member' => '13800138002', 'owner_b' => '13800138003'] as $key => $phone) {
        $account->execute(['+86' . $phone, $date, $date]);
        $ids[$key] = (int) $sql->lastInsertId();
    }
    $sql->prepare("INSERT INTO starter_platform_admins (account_id, status, created_at, updated_at) VALUES (?, 'active', ?, ?)")
        ->execute([$ids['owner_a'], $date, $date]);
    $org = $sql->prepare('INSERT INTO starter_organizations '
        . '(name, activated_at, suspended_at, archived_at, subscription_starts_at, subscription_expires_at, seat_limit) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ([
        'a' => [$date, null, null, $now - 600, $now + 900, 4],
        'b' => [$date, null, null, $now - 600, $now + 900, 4],
        'race' => [$date, null, null, $now - 600, $now + 900, 3],
        'inactive' => [$date, null, null, $now + 1, $now + 900, 4],
        'expired' => [$date, null, null, $now - 600, $now, 4],
        'suspended' => [$date, $date, null, $now - 600, $now, 4],
        'archived' => [$date, $date, $date, $now - 600, $now, 4],
        'pending' => [null, null, null, $now - 600, $now, 4],
    ] as $key => [$activated, $suspended, $archived, $start, $end, $seats]) {
        $org->execute([$key, $activated, $suspended, $archived, gmdate('Y-m-d H:i:s', $start), gmdate('Y-m-d H:i:s', $end), $seats]);
        $ids[$key] = (int) $sql->lastInsertId();
    }
    $membership = $sql->prepare('INSERT INTO starter_memberships (organization_id, account_id, role) VALUES (?, ?, ?)');
    foreach (['a', 'race', 'inactive', 'expired', 'suspended', 'archived', 'pending'] as $key) {
        $membership->execute([$ids[$key], $ids['owner_a'], 'owner']);
        $membership->execute([$ids[$key], $ids['admin'], 'admin']);
    }
    $membership->execute([$ids['a'], $ids['member'], 'member']);
    $membership->execute([$ids['b'], $ids['owner_b'], 'owner']);

    return $ids;
}

/** @param array<string,int> $ids */
function checkIssue18(PDO $sql, array $ids, int $now, callable $request, callable $race): void
{
    $expect = static function (array $response, int $status, string $code, string $label): mixed {
        [$actualStatus, $body] = $response;
        $actualCode = $body['code'] ?? null;
        check($actualStatus === $status && $actualCode === $code, "$label: expected $status $code, got $actualStatus $actualCode");
        check(is_string($body['trace_id'] ?? null) && $body['trace_id'] !== '', "$label: missing trace ID");

        return $body['data'];
    };
    $path = static fn (string $org): string => '/saas/organizations/' . $ids[$org] . '/invitations';
    $create = static fn (string $org, ?string $who, mixed $phone, array $extra = []): array =>
        $request('POST', $path($org), $who, ['phone' => $phone] + $extra);
    $keep = static fn (string $org, ?string $who, int $id): array => $request('GET', $path($org) . "/$id/link", $who);
    $revoke = static fn (string $org, string $who, int $id): array => $request('DELETE', $path($org) . "/$id", $who);
    $reissue = static fn (string $org, string $who, int $id): array => $request('POST', $path($org) . "/$id/reissue", $who);
    $invitations = static function (string $org) use ($sql, $ids): array {
        $stmt = $sql->prepare('SELECT id, status, reserved, expires_at, replaced_at, role '
            . 'FROM starter_invitations WHERE organization_id = ? ORDER BY id');
        $stmt->execute([$ids[$org]]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };
    $active = static fn (string $org): array => array_values(array_filter($invitations($org),
        static fn (array $row): bool => $row['status'] === 'pending' && $row['reserved'] === 1 && strtotime($row['expires_at'] . ' UTC') > $now));
    $phone = '13900139001';
    $other = '13900139002';
    $expect($create('a', null, $phone), 401, 'unauthenticated', 'Management requires a session');
    $expect($create('a', 'platform', $phone), 403, 'token_purpose_mismatch', 'Platform token cannot impersonate an organization owner');
    $expect($create('a', 'owner_b', $phone), 404, 'not_found', 'Other tenant cannot invite');
    $expect($create('a', 'member', ['bad'], ['role' => 'admin']), 403, 'forbidden', 'Membership authorization precedes input validation');
    $expect($create('a', 'owner_a', $phone, ['role' => 'admin']), 403, 'forbidden', 'Reject admin role');
    $expect($create('a', 'admin', $phone, ['role' => 'owner']), 422, 'validation_failed', 'Reject owner role');
    $expect($create('a', 'admin', '123'), 422, 'phone_invalid', 'Reject invalid mobile number');
    foreach (['inactive' => 'subscription_inactive', 'expired' => 'subscription_expired', 'suspended' => 'organization_suspended',
        'archived' => 'organization_archived', 'pending' => 'organization_pending'] as $org => $code) {
        $expect($create($org, 'owner_a', $phone), 403, $code, 'Lifecycle gate before invitation');
    }
    check($invitations('a') === [], 'Rejected requests must leave no invitation history');

    $a = $expect($create('a', 'admin', $phone), 201, 'success', 'Admin can reserve the final seat');
    $aId = $a['invitation_id'];
    check($a['expires_at'] === gmdate(DATE_ATOM, $now + 604800), 'First link expires in seven days');
    $aFragment = (string) parse_url($a['link'], PHP_URL_FRAGMENT);
    check(preg_match('/\Atoken=([0-9a-f]{32})\.[0-9a-f]{64}\z/', $aFragment, $aLinkParts) === 1
        && !str_contains($a['link'], $phone) && !str_contains($a['link'], '+86'), 'Link contains a signed selector and no phone');
    check($sql->query("SELECT selector FROM starter_invitations WHERE id = $aId")->fetchColumn() === $aLinkParts[1],
        'Only the selector is persisted for link reconstruction');
    $expect($create('a', 'member', $other), 403, 'forbidden', 'Full organization does not alter member authorization');
    $expect($create('a', 'owner_a', $other), 409, 'seat_limit_exceeded', 'New phone cannot reserve beyond seat cap');
    foreach (['13800138000', '13800138001', '13800138002'] as $existing) {
        $expect($create('a', 'owner_a', $existing), 409, 'member_already_exists', 'Existing member beats full capacity');
    }
    $expect($create('a', 'owner_a', '+86' . $phone), 409, 'invitation_already_exists', 'Duplicate normalized phone beats full capacity');
    check($expect($keep('a', 'owner_a', $aId), 200, 'success', 'Keep valid link while full') === $a, 'Keep preserves the response exactly');
    $expect($keep('a', 'member', $aId), 403, 'forbidden', 'Member cannot keep a link');
    $expect($revoke('a', 'member', $aId), 403, 'forbidden', 'Member cannot revoke a link');
    $expect($reissue('a', 'member', $aId), 403, 'forbidden', 'Member cannot reissue a link');
    $expect($keep('b', 'owner_b', $aId), 404, 'not_found', 'Other tenant cannot retrieve an invitation ID');
    $expect($revoke('b', 'owner_b', $aId), 404, 'not_found', 'Other tenant cannot revoke an invitation ID');
    $expect($reissue('b', 'owner_b', $aId), 404, 'not_found', 'Other tenant cannot replace an invitation ID');
    $b = $expect($create('b', 'owner_b', '+86' . $phone), 201, 'success', 'Different organization can invite the same phone');
    check($b['link'] !== $a['link'] && count($active('a')) === 1 && count($active('b')) === 1, 'Links and reservations are tenant specific');
    $expect($create('b', 'owner_b', '13800138002'), 201, 'success', 'Other tenant membership does not block a new invitation');

    $sql->exec("UPDATE starter_organizations SET subscription_expires_at = '" . gmdate('Y-m-d H:i:s', $now) . "' WHERE id = " . $ids['a']);
    $expect($keep('a', 'owner_a', $aId), 403, 'subscription_expired', 'Expired subscription rejects link retrieval');
    $expect($reissue('a', 'owner_a', $aId), 403, 'subscription_expired', 'Expired subscription rejects link replacement');
    check($expect($revoke('a', 'admin', $aId), 200, 'success', 'Expired subscription permits authorized revocation') === null,
        'Revocation uses the standard deletion envelope');
    check($active('a') === [] && $invitations('a')[0]['status'] === 'revoked' && $invitations('a')[0]['reserved'] === 0,
        'Revocation preserves history and releases the seat');
    $sql->exec("UPDATE starter_organizations SET subscription_expires_at = '" . gmdate('Y-m-d H:i:s', $now + 900) . "' WHERE id = " . $ids['a']);
    $expect($keep('a', 'owner_a', $aId), 409, 'invitation_invalid', 'Revoked link cannot be retrieved');
    $replacement = $expect($reissue('a', 'owner_a', $aId), 201, 'success', 'Reissue an explicitly revoked invite when a seat is available');
    $replacementId = $replacement['invitation_id'];
    check($replacementId !== $aId && $replacement['link'] !== $a['link'], 'Reissue creates a different row and credential');

    $proof = static function (array $invitation, ?string $credential = null) use ($request): array {
        $token = substr((string) parse_url($invitation['link'], PHP_URL_FRAGMENT), strlen('token='));

        return $request('POST', '/fixture/invitation/proof', null, ['token' => $token, 'credential' => $credential]);
    };
    $proofData = $expect($proof($replacement), 200, 'success', 'Seed a real Redis invitation proof');
    check($proofData['expires_at'] === $now + 600, 'Verification proof has its own ten-minute lifetime');
    $credential = $proofData['credential'];
    $expect($proof($replacement, $credential), 200, 'success', 'Current invitation proof is valid before reissue');

    $beforeFailedAudit = $invitations('a');
    $sql->exec("CREATE TRIGGER fixture_issue18_fail_audit BEFORE INSERT ON starter_audit_logs FOR EACH ROW "
        . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Test audit write rejection'");
    try {
        $expect($reissue('a', 'owner_a', $replacementId), 503, 'audit_unavailable', 'Audit rejection aborts replacement');
    } finally {
        $sql->exec('DROP TRIGGER fixture_issue18_fail_audit');
    }
    check($invitations('a') === $beforeFailedAudit, 'Replacement rollback restores status, reservations and history');
    check($expect($keep('a', 'owner_a', $replacementId), 200, 'success', 'Original survives failed replacement') === $replacement,
        'Failed reissue leaves the original link usable');
    $expect($proof($replacement), 429, 'sms_rate_limited', 'Keeping the link preserves the verification send interval');
    $expect($proof($replacement, $credential), 200, 'success', 'Rollback preserves the old proof as well');
    $beyondCap = $expect($reissue('a', 'admin', $replacementId), 201, 'success', 'Valid reservation can be replaced at full capacity');
    $expect($proof($replacement, $credential), 409, 'invitation_invalid', 'Old proof is unusable immediately after reissue');
    $expect($proof($beyondCap, $credential), 401, 'purpose_mismatch', 'Old proof cannot be carried forward to the new invitation');
    $expect($proof($beyondCap), 429, 'sms_rate_limited', 'Replacing the link preserves the verification send interval');
    check(count($active('a')) === 1 && $active('a')[0]['id'] === $beyondCap['invitation_id'], 'Full capacity replacement holds only its new seat');
    $expect($keep('a', 'owner_a', $replacementId), 409, 'invitation_invalid', 'Replacement immediately invalidates the old invitation');
    $expect($reissue('a', 'owner_a', $replacementId), 409, 'invitation_invalid', 'Superseded row cannot be reissued a second time');

    $end = gmdate('Y-m-d H:i:s', $now);
    $expiredId = $beyondCap['invitation_id'];
    $sql->exec("UPDATE starter_invitations SET expires_at = '$end' WHERE id = $expiredId");
    $expect($keep('a', 'owner_a', $expiredId), 409, 'invitation_invalid', 'An invitation expires at the exact end instant');
    $otherInvite = $expect($create('a', 'owner_a', $other), 201, 'success', 'Expired invitation does not occupy the final seat');
    $beforeNoSeat = $invitations('a');
    $expect($reissue('a', 'owner_a', $expiredId), 409, 'seat_limit_exceeded', 'Reissue with released reservation requires a free seat');
    check($invitations('a') === $beforeNoSeat, 'Seat rejection preserves original invitation states');
    $expect($revoke('a', 'owner_a', $otherInvite['invitation_id']), 200, 'success', 'Release the other outstanding invite');
    $final = $expect($reissue('a', 'owner_a', $expiredId), 201, 'success', 'Reissue replaces an expired invitation with capacity');
    check($final['expires_at'] === gmdate(DATE_ATOM, $now + 604800), 'Reissued invite lasts seven days');

    $racedPhone = '13900139003';
    $outcomes = $race($ids['race'], [
        ['POST', $path('race'), 'owner_a', ['phone' => $racedPhone]],
        ['POST', $path('race'), 'admin', ['phone' => '+86' . $racedPhone]],
    ]);
    usort($outcomes, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
    $winner = $expect($outcomes[0], 201, 'success', 'One concurrent normalized create succeeds');
    $expect($outcomes[1], 409, 'invitation_already_exists', 'Other concurrent normalized create conflicts');
    check(count($invitations('race')) === 1 && count($active('race')) === 1, 'Create race leaves only one reservation and row');
    $winnerId = $winner['invitation_id'];
    $outcomes = $race($ids['race'], [
        ['POST', $path('race') . "/$winnerId/reissue", 'owner_a'],
        ['POST', $path('race') . "/$winnerId/reissue", 'admin'],
    ]);
    usort($outcomes, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
    $winnerAfter = $expect($outcomes[0], 201, 'success', 'One concurrent reissue succeeds');
    $expect($outcomes[1], 409, 'invitation_invalid', 'Other concurrent reissue sees the superseded row');
    check(count($invitations('race')) === 2 && count($active('race')) === 1
        && $active('race')[0]['id'] === $winnerAfter['invitation_id'], 'Reissue race leaves only one live row and reservation');

    $rows = $sql->query('SELECT organization_id, action, actor_account_id, metadata FROM starter_audit_logs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        check((int) $row['organization_id'] > 0 && (int) $row['actor_account_id'] > 0, 'Audit identifies organization and actor');
        check(!str_contains($row['metadata'], $phone) && !str_contains($row['metadata'], $other)
            && !str_contains($row['metadata'], $racedPhone) && !str_contains($row['metadata'], 'token=')
            && !str_contains($row['metadata'], $aLinkParts[1]), 'Audit omits full phone, selector and token');
        $meta = json_decode($row['metadata'], true, flags: JSON_THROW_ON_ERROR);
        check(preg_match('/\A\*{10}\d{4}\z/', $meta['phone_masked'] ?? '') === 1
            && $meta['result'] === 'success' && $meta['role'] === 'member', 'Audit carries only masked contact and necessary lifecycle metadata');
    }
    check(array_count_values(array_column($rows, 'action')) === [
        'invitation_created' => 5,
        'invitation_link_retrieved' => 2,
        'invitation_revoked' => 2,
        'invitation_reissued' => 4,
    ], 'Audit covers all successful operations without extra writes for rejection');
}

function issue18Curl(int $port, array $tokens, string $method, string $path, ?string $actor = null, ?array $data = null): \CurlHandle
{
    $curl = curl_init("http://127.0.0.1:$port$path");
    $headers = ['Content-Type: application/json', 'Connection: close'];
    if ($actor !== null) {
        $headers[] = 'Authorization: Bearer ' . $tokens[$actor];
    }
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 8, CURLOPT_HTTPHEADER => $headers]);
    if ($data !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data, JSON_THROW_ON_ERROR));
    }

    return $curl;
}

function checkInstalledIssue18(string $page, int $now): void
{
    $fixture = new Fixtures();
    try {
        $qa = prepareD2Host($fixture, 'qa_invites');
        $sql = $fixture->database('qa_invites', create: false);
        $ids = seedIssue18($sql, $now);
        $qa->clock($now);
        $qa->withHost('invitation-management', static function (int $port) use ($qa, $sql, $ids, $page, $now): void {
            $session = static function (string $phone, string $purpose) use ($qa, $port): array {
                $challenge = $qa->issue($port, $phone);

                return ['Authorization: Bearer ' . smsToken(smsLogin($port, $challenge, phone: $phone, purpose: $purpose))];
            };
            $owner = $session('13800138000', 'organization');
            $admin = $session('13800138001', 'organization');
            $ordinary = $session('13800138002', 'organization');
            $qa->clock($now + 60);
            $platform = $session('13800138000', 'platform');
            $smsBefore = $qa->messages();
            $emailBefore = emailMessages($qa);
            $auditBefore = (int) $sql->query('SELECT COUNT(*) FROM starter_audit_logs')->fetchColumn();
            $path = '/saas/organizations/' . $ids['a'] . '/invitations';
            $target = ['phone' => '13900139001'];
            smsExpect(smsRequest($port, 'POST', $path, $target), 401, 'unauthenticated', 'Installed route requires authentication');
            smsExpect(smsRequest($port, 'POST', $path, $target, $platform), 403, 'token_purpose_mismatch', 'Installed route rejects platform purpose');
            smsExpect(smsRequest($port, 'POST', $path, $target, $ordinary), 403, 'forbidden', 'Installed route rejects ordinary member');
            $first = smsExpect(smsRequest($port, 'POST', $path, $target, $admin), 201, 'success', 'Installed admin creates an invitation')['data'];
            check(str_starts_with($first['link'], $page . '#token=')
                && $first['expires_at'] === gmdate(DATE_ATOM, $now + 60 + 604800), 'Installed host uses its configured page and clock');
            $resource = $path . '/' . $first['invitation_id'];
            $kept = smsExpect(smsRequest($port, 'GET', $resource . '/link', headers: $owner), 200, 'success', 'Installed owner keeps the link')['data'];
            check($first === $kept, 'Installed route keeps the original link');
            $qa->clock($now + 61);
            $new = smsExpect(smsRequest($port, 'POST', $resource . '/reissue', headers: $owner), 201, 'success', 'Installed owner replaces the link')['data'];
            check($new['link'] !== $first['link'] && $new['expires_at'] === gmdate(DATE_ATOM, $now + 61 + 604800),
                'Installed reissue returns a fresh credential and expiration');
            smsExpect(smsRequest($port, 'GET', $resource . '/link', headers: $owner), 409, 'invitation_invalid', 'Installed old link is invalid');
            smsExpect(smsRequest($port, 'DELETE', $path . '/' . $new['invitation_id'], headers: $admin), 200, 'success', 'Installed admin revokes');
            check($qa->messages() === $smsBefore && emailMessages($qa) === $emailBefore,
                'Installed management operations send neither SMS nor email');
            check((int) $sql->query('SELECT COUNT(*) FROM starter_audit_logs')->fetchColumn() === $auditBefore + 4,
                'Installed management actions write four audit events');
        }, ['STARTER_INVITATION_PAGE_URL' => $page]);
    } finally {
        $fixture->close();
    }
}

$fixture = new Fixtures();
try {
    $fixture->startServices();
    $sql = $fixture->database('qa_invitation');
    $fixture->run([PHP_BINARY, 'vendor/bin/phinx', 'migrate', '-c', 'phinx.php'], 'Migrate issue #18 schema', dirname(__DIR__));
    $now = 1_800_000_000;
    $ids = seedIssue18($sql, $now);
    $port = $fixture->port();
    $hostScript = __DIR__ . '/host/issue-18/start.php';
    $env = ['ISSUE18_PORT' => (string) $port, 'ISSUE18_NOW' => (string) $now, 'ISSUE18_PAGE' => $page,
        'ISSUE18_SECRET' => bin2hex(random_bytes(32))];
    $issuer = $fixture->start([PHP_BINARY, $hostScript, 'tokens'], env: $env, pipeInput: true);
    $issuer->writeInput(json_encode(array_intersect_key($ids, array_flip(['owner_a', 'admin', 'member', 'owner_b']))
        + ['platform' => $ids['owner_a']], JSON_THROW_ON_ERROR));
    [$exit, $output] = $issuer->result();
    check($exit === 0, "Could not issue test sessions: $output");
    $tokens = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    $workers = [$port => $fixture->start([PHP_BINARY, $hostScript, 'start'], env: $env)];
    $secondPort = $fixture->port();
    $workers[$secondPort] = $fixture->start([PHP_BINARY, $hostScript, 'start'], env: ['ISSUE18_PORT' => (string) $secondPort] + $env);
    $request = static function (string $method, string $path, ?string $actor = null, ?array $data = null) use ($port, $tokens): array {
        $curl = issue18Curl($port, $tokens, $method, $path, $actor, $data);
        $response = curl_exec($curl);

        return [curl_getinfo($curl, CURLINFO_RESPONSE_CODE), is_string($response) ? json_decode($response, true) : null];
    };
    foreach ($workers as $workerPort => $worker) {
        $fixture->ready($worker, static function () use ($workerPort): bool {
            $curl = issue18Curl($workerPort, [], 'GET', '/fixture/health');

            return curl_exec($curl) !== false && curl_getinfo($curl, CURLINFO_RESPONSE_CODE) === 200;
        }, 'Issue #18 Webman worker');
    }

    $race = static function (int $org, array $requests) use ($sql, $port, $secondPort, $tokens): array {
        $multi = curl_multi_init();
        $handles = [];
        $sql->beginTransaction();
        try {
            // Release the organization lock only after both different Webman workers are waiting on it.
            $sql->query("SELECT id FROM starter_organizations WHERE id = $org FOR UPDATE")->fetchColumn();
            foreach ($requests as $index => $arguments) {
                $handle = issue18Curl($index === 0 ? $port : $secondPort, $tokens, ...$arguments);
                $handles[] = $handle;
                curl_multi_add_handle($multi, $handle);
            }
            $waiting = $sql->prepare('SELECT COUNT(*) FROM information_schema.PROCESSLIST '
                . 'WHERE ID <> CONNECTION_ID() AND DB = DATABASE() AND INFO LIKE ?');
            $deadline = microtime(true) + 4;
            do {
                curl_multi_exec($multi, $running);
                $waiting->execute(['%from %starter_organizations%for update%']);
                $waitingCount = (int) $waiting->fetchColumn();
                if ($waitingCount === count($requests)) {
                    break;
                }
                check($running > 0 && microtime(true) < $deadline, 'Both HTTP workers must reach the organization lock');
                usleep(1_000);
            } while (true);
            $sql->commit();
            do {
                curl_multi_exec($multi, $running);
                if ($running > 0) {
                    curl_multi_select($multi, 0.1);
                }
            } while ($running > 0);

            return array_map(static fn (\CurlHandle $curl): array => [
                curl_getinfo($curl, CURLINFO_RESPONSE_CODE), json_decode(curl_multi_getcontent($curl), true),
            ], $handles);
        } finally {
            if ($sql->inTransaction()) {
                $sql->rollBack();
            }
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
            }
            curl_multi_close($multi);
        }
    };
    checkIssue18($sql, $ids, $now, $request, $race);
    echo "PASS #18 invitation lifecycle: Webman HTTP, MySQL seats and history, Redis sessions and proofs, concurrent create and replacement.\n";
} finally {
    $fixture->close();
}
checkInstalledIssue18($page, $now);
echo "PASS #18 installed starter routes: configured page, production SMS login, role checks, lifecycle and silent sharing.\n";
