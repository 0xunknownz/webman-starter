<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\email;

use Z0x\WebmanStarter\saas\domain\SubmissionStatus;

interface EmailSenderInterface
{
    /** Accepted indicates submission to the mail provider. */
    public function send(string $email, #[\SensitiveParameter] string $code, string $purpose): SubmissionStatus;
}
