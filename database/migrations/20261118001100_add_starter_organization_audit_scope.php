<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddStarterOrganizationAuditScope extends AbstractMigration
{
    public function change(): void
    {
        $this->table('starter_audit_logs')
            ->addColumn('organization_id', 'integer', ['signed' => false, 'null' => true])
            ->addIndex(['organization_id', 'created_at', 'id'], ['name' => 'starter_audits_org_time'])
            ->update();
    }
}
