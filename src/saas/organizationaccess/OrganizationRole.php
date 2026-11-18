<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\organizationaccess;

enum OrganizationRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
}
