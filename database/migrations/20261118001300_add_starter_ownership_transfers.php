<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class AddStarterOwnershipTransfers extends AbstractMigration
{
    public function up(): void
    {
        $this->table('starter_ownership_transfers', ['id' => false, 'primary_key' => ['id'], 'engine' => 'InnoDB'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false, 'null' => false])
            ->addColumn('organization_id', 'integer', ['signed' => false, 'null' => false])
            // Historical IDs deliberately do not take account FK locks after the organization lock.
            ->addColumn('proposer_account_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('recipient_account_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('status', 'enum', ['values' => ['pending', 'revoked', 'expired', 'completed', 'invalidated'], 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('resolved_at', 'datetime', ['null' => true])
            ->addIndex(['organization_id', 'id'], ['name' => 'starter_transfer_organization_resource'])
            ->addIndex(['organization_id', 'status', 'expires_at'], ['name' => 'starter_transfer_organization_pending'])
            ->addIndex(['recipient_account_id', 'status', 'organization_id', 'expires_at'], ['name' => 'starter_transfer_recipient_pending'])
            ->addForeignKey('organization_id', 'starter_organizations', 'id')
            ->create();
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Ownership transfer history must be retained.');
    }
}
