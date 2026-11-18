<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\invitation;

use InvalidArgumentException;
use SensitiveParameter;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

/** The fragment is read by the product page and passed in the body of the dedicated invitation API. */
final class InvitationLink
{
    private readonly string $secret;

    public function __construct(private readonly string $pageUrl, #[SensitiveParameter] string $secret)
    {
        $url = parse_url($pageUrl);
        if ($url === false || filter_var($pageUrl, FILTER_VALIDATE_URL) === false
            || !in_array($url['scheme'] ?? null, ['https', 'http'], true)
            || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || strlen($secret) < 32) {
            throw new InvalidArgumentException('Invitation links require an absolute HTTP(S) page without credentials or a fragment and a secret of at least 32 bytes.');
        }
        $this->secret = $secret;
    }

    public function forSelector(string $selector): string
    {
        return $this->pageUrl . '#token=' . $selector . '.' . $this->signature($selector);
    }

    public function selector(#[SensitiveParameter] string $token): string
    {
        if (preg_match('/\A([0-9a-f]{32})\.([0-9a-f]{64})\z/', $token, $parts) !== 1
            || !hash_equals($this->signature($parts[1]), $parts[2])) {
            throw BusinessException::notFound();
        }

        return $parts[1];
    }

    private function signature(string $selector): string
    {
        return hash_hmac('sha256', 'starter:invitation:v1:' . $selector, $this->secret);
    }
}
