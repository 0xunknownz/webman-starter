<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\domain;

enum SubmissionStatus
{
    case Accepted;
    case Rejected;
    case TimedOut;
}
