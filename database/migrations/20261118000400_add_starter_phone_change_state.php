<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddStarterPhoneChangeState extends AbstractMigration
{
    public function change(): void
    {
        $phone = ['limit' => 16, 'encoding' => 'ascii', 'collation' => 'ascii_bin', 'null' => false];
        $this->table('starter_phone_changes', ['id' => false, 'primary_key' => ['id'], 'engine' => 'InnoDB'])
            ->addColumn('id', 'string', ['limit' => 32, 'encoding' => 'ascii', 'collation' => 'ascii_bin', 'null' => false])
            ->addColumn('account_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('old_phone', 'string', $phone)
            ->addColumn('old_phone_epoch', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('new_phone', 'string', $phone)
            ->addColumn('mode', 'enum', ['values' => ['normal', 'email_recovery', 'manual_recovery', 'offline_recovery'], 'null' => false])
            ->addColumn('source_verified_until', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('new_verified_until', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('actor_account_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('actor_ref', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('reason_ref', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['new_phone'])
            ->addForeignKey('account_id', 'starter_accounts', 'id')
            ->create();

        $this->table('starter_invitations')
            ->addColumn('account_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addForeignKey('account_id', 'starter_accounts', 'id')
            ->update();

        $this->table('starter_memberships', [
            'id' => false,
            'primary_key' => ['organization_id', 'account_id'],
            'engine' => 'InnoDB',
        ])
            ->addColumn('organization_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('account_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('role', 'enum', ['values' => ['owner', 'admin', 'member'], 'null' => false])
            ->addForeignKey('organization_id', 'starter_organizations', 'id')
            ->addForeignKey('account_id', 'starter_accounts', 'id')
            ->create();
    }
}
