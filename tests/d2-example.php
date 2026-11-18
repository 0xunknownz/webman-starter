<?php

declare(strict_types=1);

namespace StarterAcceptance;

require __DIR__ . '/d2-fixtures.php';

/** @return array<string,mixed> */
function d2ExampleRequest(int $port, string $method, string $path, ?array $input = null, ?string $access = null, int $status = 200, string $code = 'success'): array
{
    return smsExpect(smsRequest($port, $method, '/saas' . $path, $input, $access === null ? [] : sessionBearer($access)),
        $status, $code, $method . ' ' . $path)['data'] ?? [];
}

function d2ExampleJoin(int $port, SmsLoginFixture $qa, string $link, string $phone, string $name, int $organizationId, string $role): string
{
    $fragment = parse_url($link, PHP_URL_FRAGMENT);
    check(is_string($fragment), 'Invitation page link must carry an opaque fragment');
    parse_str($fragment, $params);
    $token = $params['token'] ?? null;
    check(is_string($token), 'Missing invite token');
    $messages = count($qa->messages());
    $resolved = d2ExampleRequest($port, 'POST', '/invitations/resolve', ['token' => $token]);
    check(count($resolved) === 4 && ($resolved['organization_name'] ?? null) === $name && ($resolved['type'] ?? null) === $role,
        'Dedicated resolve shows only the intended organization and invite preview fields');
    check(count($qa->messages()) === $messages, 'Invitation preview must not send SMS');
    $input = ['token' => $token, 'phone' => $phone];
    $send = d2ExampleRequest($port, 'POST', '/invitations/sms/send', $input);
    $captured = $qa->messages();
    check(count($captured) === $messages + 1 && $captured[$messages]['purpose'] === 'invitation', 'User-initiated invitation SMS');
    $verified = d2ExampleRequest($port, 'POST', '/invitations/sms/verify', $input + [
        'challenge_id' => $send['challenge_id'], 'code' => $captured[$messages]['code'],
    ]);
    $accepted = d2ExampleRequest($port, 'POST', '/invitations/accept', $input + ['credential' => $verified['credential']]);
    check((int) $accepted['organization_id'] === $organizationId && $accepted['role'] === $role, 'Invitation joins the named organization');

    return smsToken(smsLogin($port, $qa->issue($port, $phone), phone: $phone, purpose: 'organization'));
}

$fixtures = new Fixtures();
try {
    $published = ($argv[1] ?? null) === '--published';
    $qa = prepareD2Host($fixtures, 'qa_example', $published);
    cliResult(init($fixtures, PHONE)->result(), true, 'platform_admin_initialized');
    [$exit, $output] = $fixtures->start([PHP_BINARY, 'bin/setup-example.php'], $fixtures->host,
        ['QA_SMS_STATE' => $qa->stateDirectory()])->result();
    check($exit === 0 && preg_match('/^basic_package_id=([1-9][0-9]*)$/m', $output, $match) === 1,
        'Example setup must report an available starter package: ' . $output);
    $package = (int) $match[1];
    $qa->withHost('d2-example', static function (int $port) use ($qa, $package): void {
        $admin = smsToken(smsLogin($port, $qa->issue($port, PHONE), phone: PHONE, purpose: 'platform'));
        $open = static fn (string $label, string $phone): array => d2ExampleRequest($port, 'POST', '/platform/organizations', [
            'name' => $label, 'phone' => $phone, 'package_id' => $package,
            'starts_at' => gmdate(DATE_ATOM, SMS_TIME - 60), 'expires_at' => gmdate(DATE_ATOM, SMS_TIME + 86400),
        ], $admin, 201);
        $alpha = $open('示例甲', '13900139001');
        $beta = $open('示例乙', '13900139002');
        $alphaId = (int) $alpha['organization_id'];
        $betaId = (int) $beta['organization_id'];
        $a = d2ExampleJoin($port, $qa, $alpha['link'], '13900139001', '示例甲', $alphaId, 'owner');
        $b = d2ExampleJoin($port, $qa, $beta['link'], '13900139002', '示例乙', $betaId, 'owner');
        $aPath = '/organizations/' . $alphaId;
        $bPath = '/organizations/' . $betaId;
        $invite = d2ExampleRequest($port, 'POST', $aPath . '/invitations', ['phone' => '13900139003'], $a, 201);
        $member = d2ExampleJoin($port, $qa, $invite['link'], '13900139003', '示例甲', $alphaId, 'member');
        $left = d2ExampleRequest($port, 'POST', $aPath . '/records', ['title' => '甲记录'], $a, 201);
        $right = d2ExampleRequest($port, 'POST', $bPath . '/records', ['title' => '乙记录'], $b, 201);
        $aItem = $aPath . '/records/' . $left['id'];
        $bItem = $bPath . '/records/' . $right['id'];
        $foreign = $aPath . '/records/' . $right['id'];
        check(d2ExampleRequest($port, 'GET', $aItem, access: $a)['title'] === '甲记录', 'Read organization A record');
        check(d2ExampleRequest($port, 'GET', $bItem, access: $b)['title'] === '乙记录', 'Read organization B record');
        $visible = d2ExampleRequest($port, 'GET', $aPath . '/records', access: $member)['records'];
        check(array_column($visible, 'title') === ['甲记录'], 'Organization A list contains only its record');
        d2ExampleRequest($port, 'GET', $foreign, access: $a, status: 404, code: 'not_found');
        d2ExampleRequest($port, 'PATCH', $foreign, ['title' => '不应写入'], $a, 404, 'not_found');
        d2ExampleRequest($port, 'GET', $bPath . '/records', access: $a, status: 404, code: 'not_found');
        d2ExampleRequest($port, 'PATCH', $aItem, ['title' => '不应写入'], $member, 403, 'forbidden');
        check(d2ExampleRequest($port, 'GET', $bItem, access: $b)['title'] === '乙记录', 'Cross-tenant write left B unchanged');
    }, ['STARTER_INVITATION_PAGE_URL' => 'https://product.example.test/join', 'STARTER_EXAMPLE_RECORDS_ENABLED' => '1']);
    echo "PASS example product opening, invitation registration and two-organization record isolation\n";
} finally {
    $fixtures->close();
}
