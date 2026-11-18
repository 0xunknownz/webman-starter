<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\contract;

use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\domain\SubmissionStatus;

interface SmsSenderInterface
{
    public function send(MainlandMobileNumber $phone, string $purpose, string $code): SubmissionStatus;
}
