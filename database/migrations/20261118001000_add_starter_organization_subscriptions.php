<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class AddStarterOrganizationSubscriptions extends AbstractMigration
{
    public function up(): void
    {
        $this->table('starter_packages', ['engine' => 'InnoDB', 'signed' => false])
            ->addColumn('name', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('seat_limit', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('retired_at', 'datetime', ['null' => true])
            ->create();

        // Legacy rows have no known package; D1 never exposed production organization creation.
        $this->table('starter_organizations')
            ->addColumn('subscription_starts_at', 'datetime', ['null' => false, 'default' => '1970-01-01 00:00:00'])
            ->addColumn('package_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('package_name', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('seat_limit', 'integer', ['signed' => false, 'null' => true])
            ->addForeignKey('package_id', 'starter_packages', 'id')
            ->update();

        $this->table('starter_invitations')
            ->addIndex(['organization_id', 'phone', 'status', 'expires_at'], ['name' => 'starter_invites_org_phone_pending'])
            ->update();
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Starter subscription data must be retained.');
    }
}
