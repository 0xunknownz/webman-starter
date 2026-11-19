<?php

declare(strict_types=1);

namespace StarterAcceptance;

require_once __DIR__ . '/d2-fixtures.php';

const D3_PAGE = 'https://product.example.test/join';
const D3_HOST_ENV = [
    'STARTER_INVITATION_PAGE_URL' => D3_PAGE,
    'STARTER_EXAMPLE_RECORDS_ENABLED' => '1',
];

/** @return array<string,mixed> */
function d3Request(int $port, string $method, string $path, ?array $input = null, ?string $access = null, int $status = 200, string $code = 'success'): array
{
    return smsExpect(smsRequest($port, $method, '/saas' . $path, $input, $access === null ? [] : sessionBearer($access)),
        $status, $code, $method . ' ' . $path)['data'] ?? [];
}

function d3Login(SmsLoginFixture $qa, int $port, string $phone, string $purpose = 'organization'): string
{
    return smsToken(smsLogin($port, $qa->issue($port, $phone), phone: $phone, purpose: $purpose));
}

/** @return array<string,mixed> */
function d3Open(int $port, string $admin, int $package, string $name, string $phone, int $startsAt = SMS_TIME - 60, int $expiresAt = SMS_TIME + 86400): array
{
    return d3Request($port, 'POST', '/platform/organizations', [
        'name' => $name,
        'phone' => $phone,
        'package_id' => $package,
        'starts_at' => gmdate(DATE_ATOM, $startsAt),
        'expires_at' => gmdate(DATE_ATOM, $expiresAt),
    ], $admin, 201);
}

function d3Token(string $link): string
{
    check(str_starts_with($link, D3_PAGE . '#token='), 'Expected the configured invitation page and token fragment');
    parse_str((string) parse_url($link, PHP_URL_FRAGMENT), $params);
    $token = $params['token'] ?? null;
    check(is_string($token) && $token !== '', 'Invitation token is missing');

    return $token;
}

/** @return array{token:string,phone:string,credential:string} */
function d3InvitationProof(SmsLoginFixture $qa, int $port, string $link, string $phone): array
{
    $input = ['token' => d3Token($link), 'phone' => $phone];
    $before = count($qa->messages());
    $sent = d3Request($port, 'POST', '/invitations/sms/send', $input);
    $captured = $qa->messages();
    check(count($captured) === $before + 1 && $captured[$before]['purpose'] === 'invitation'
        && $captured[$before]['phone'] === '+86' . $phone, 'Expected one invitation SMS for its recipient');
    $proof = d3Request($port, 'POST', '/invitations/sms/verify', $input + [
        'challenge_id' => $sent['challenge_id'], 'code' => $captured[$before]['code'],
    ]);

    return $input + ['credential' => $proof['credential']];
}

function d3Join(int $port, SmsLoginFixture $qa, string $link, string $phone, int $organizationId, string $role): string
{
    $accepted = d3Request($port, 'POST', '/invitations/accept', d3InvitationProof($qa, $port, $link, $phone));
    check((int) $accepted['organization_id'] === $organizationId && $accepted['role'] === $role,
        'Invitation must join the requested organization with the expected role');

    return d3Login($qa, $port, $phone);
}
