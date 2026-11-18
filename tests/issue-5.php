<?php

declare(strict_types=1);

namespace StarterAcceptance;

const ACCOUNT_PROFILE = '/saas/account/profile';
const PROFILE_MEMBER = '13800138251';

/** @param array{int,array<string,mixed>} $response */
function expectProfile(array $response, int $accountId, ?string $name, string $label): void
{
    $data = smsExpect($response, 200, 'success', $label)['data'];
    $expected = ['account_id' => $accountId, 'display_name' => $name, 'display_label' => $name ?? '未设置姓名'];
    check(is_array($data) && count($data) === count($expected), "$label returned unexpected profile fields");
    foreach ($expected as $field => $value) {
        check(array_key_exists($field, $data) && $data[$field] === $value, "$label returned an unexpected $field");
    }
}

function testProfileInvalidUtf8(int $port, string $token): void
{
    $body = "{\"display_name\":\"\xC3\x28\"}";
    $length = strlen($body);
    $path = ACCOUNT_PROFILE;
    $request = "PATCH $path HTTP/1.1\r\nHost: 127.0.0.1:$port\r\nConnection: close\r\n"
        . "Authorization: Bearer $token\r\nContent-Type: application/json\r\nContent-Length: $length\r\n\r\n$body";
    $stream = stream_socket_client("tcp://127.0.0.1:$port", timeout: 1);
    check(is_resource($stream), 'Could not connect to disposable profile host');
    stream_set_timeout($stream, 6);
    try {
        check(fwrite($stream, $request) === strlen($request), 'Could not send malformed UTF-8 profile request');
        smsExpect(smsReadResponse($stream), 422, 'validation_failed', 'Invalid UTF-8 display name');
    } finally {
        fclose($stream);
    }
}

function testProfile(Fixtures $fixtures, SmsLoginFixture $smsLogin): void
{
    $db = $fixtures->database('qa_profile');
    $fixtures->run([PHP_BINARY, 'vendor/bin/phinx', 'migrate', '-c', 'vendor/' . PACKAGE . '/phinx.php',
        '--target', '20261118000100'], 'Migrate QA profile database to the pre-profile version', $fixtures->host);
    cliResult(init($fixtures, PHONE)->result(), true, 'platform_admin_initialized');
    $platformId = (int) $db->query("SELECT account_id FROM starter_platform_admins WHERE status = 'active'")->fetchColumn();
    $db->prepare('INSERT INTO starter_accounts (phone, created_at, updated_at) VALUES (?, NOW(), NOW())')
        ->execute(['+86' . PROFILE_MEMBER]);
    $memberId = (int) $db->lastInsertId();
    check($platformId > 0 && $memberId > 0 && $platformId !== $memberId, 'Expected two distinct QA profile accounts');
    migrate($fixtures);

    $smsLogin->withHost('profile', static function (int $port) use ($smsLogin, $platformId, $memberId): void {
        $smsLogin->clock(SMS_TIME);
        $platformToken = smsToken(smsLogin($port, $smsLogin->issue($port)));
        $memberToken = smsToken(smsLogin($port, $smsLogin->issue($port, PROFILE_MEMBER),
            phone: PROFILE_MEMBER, purpose: 'organization'));
        $platform = ["Authorization: Bearer $platformToken"];
        $member = ["Authorization: Bearer $memberToken"];

        expectProfile(smsRequest($port, 'GET', ACCOUNT_PROFILE, headers: $platform), $platformId, null, 'Unset platform name');
        expectProfile(smsRequest($port, 'GET', ACCOUNT_PROFILE, headers: $member), $memberId, null, 'Unset member name');
        expectProfile(smsRequest($port, 'PATCH', ACCOUNT_PROFILE, ['display_name' => "\u{3000}😀\u{00A0}"], $member),
            $memberId, '😀', 'One Unicode character after trimming');
        smsExpect(smsRequest($port, 'GET', ACCOUNT_PROFILE), 401, 'unauthenticated', 'Profile read without Bearer');
        smsExpect(smsRequest($port, 'PATCH', ACCOUNT_PROFILE, ['display_name' => '越权']), 401, 'unauthenticated',
            'Profile update without Bearer');
        expectProfile(smsRequest($port, 'GET', ACCOUNT_PROFILE, headers: $platform), $platformId, null,
            'Request following a denial kept its own identity');

        $name = str_repeat('名', 49) . '😀';
        expectProfile(smsRequest($port, 'PATCH', ACCOUNT_PROFILE, ['account_id' => $platformId, 'display_name' => " $name "], $member),
            $memberId, $name, 'Fifty Unicode characters scoped to the authenticated account');
        expectProfile(smsRequest($port, 'GET', ACCOUNT_PROFILE . '?account_id=' . $memberId, headers: $platform),
            $platformId, null, 'Query account ID did not select another profile');
        expectProfile(smsRequest($port, 'PATCH', ACCOUNT_PROFILE, ['display_name' => $name], $platform),
            $platformId, $name, 'Two accounts may share a display name');
        expectProfile(smsRequest($port, 'PATCH', ACCOUNT_PROFILE . '?display_name=ignored', [], $member),
            $memberId, $name, 'Omitting the field keeps the current name');

        foreach ([str_repeat('名', 51), '   ', "\u{00A0}\u{3000}", "\t名", "名\n", "\u{0000}名", "名\u{0085}", "名\u{2028}字", "名\u{2029}字", 123, ['名']] as $invalid) {
            smsExpect(smsRequest($port, 'PATCH', ACCOUNT_PROFILE, ['display_name' => $invalid], $member),
                422, 'validation_failed', 'Invalid display name');
        }
        testProfileInvalidUtf8($port, $memberToken);
        expectProfile(smsRequest($port, 'GET', ACCOUNT_PROFILE, headers: $platform), $platformId, $name,
            'Request following a validation exception kept its own identity');
        expectProfile(smsRequest($port, 'GET', ACCOUNT_PROFILE, headers: $member), $memberId, $name,
            'Validation failures kept the stored name');
        expectProfile(smsRequest($port, 'PATCH', ACCOUNT_PROFILE, ['display_name' => null], $member),
            $memberId, null, 'Null clears the stored name');
        expectProfile(smsRequest($port, 'GET', ACCOUNT_PROFILE, headers: $platform), $platformId, $name,
            'Clearing one account preserved the other profile');
        expectProfile(smsRequest($port, 'PATCH', ACCOUNT_PROFILE, ['display_name' => ''], $platform),
            $platformId, null, 'Empty string clears the stored name');
        expectProfile(smsRequest($port, 'GET', ACCOUNT_PROFILE, headers: $member), $memberId, null, 'Member name stayed cleared');
        expectProfile(smsRequest($port, 'GET', ACCOUNT_PROFILE, headers: $platform), $platformId, null, 'Platform name stayed cleared');
    }, ['WEBMAN_STARTER_HTTP_WORKERS' => '1']);
    echo "PASS profile validation, nullable names, and account isolation on one Webman HTTP worker\n";
}
