<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\http;

use InvalidArgumentException;
use Webman\Http\Request;

final class ClientAddress
{
    /** @var list<string> */
    private readonly array $trusted;

    /** @param list<string> $trustedProxies */
    public function __construct(array $trustedProxies = [])
    {
        $this->trusted = array_map(static function (string $ip): string {
            return self::normalize($ip) ?? throw new InvalidArgumentException('Invalid trusted proxy address.');
        }, $trustedProxies);
    }

    public function resolve(Request $request): string
    {
        $remote = self::normalize($request->getRemoteIp()) ?? 'unknown';
        if (!in_array($remote, $this->trusted, true)) {
            return $remote;
        }
        $chain = $request->header('x-forwarded-for');
        if (!is_string($chain) || strlen($chain) > 2048) {
            return $remote;
        }
        $hops = array_map(static fn (string $ip): ?string => self::normalize(trim($ip)), explode(',', $chain));
        if (in_array(null, $hops, true)) {
            return $remote;
        }
        foreach (array_reverse($hops) as $ip) {
            if (!in_array($ip, $this->trusted, true)) {
                return $ip;
            }
        }

        return $remote;
    }

    private static function normalize(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $packed = substr($packed, 12);
        }

        return inet_ntop($packed);
    }
}
