<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddStarterLoginState extends AbstractMigration
{
    public function change(): void
    {
        $this->table('starter_accounts')
            ->addColumn('session_epoch', 'biginteger', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('phone_epoch', 'biginteger', ['signed' => false, 'null' => false, 'default' => 0])
            ->update();
        $this->table('starter_platform_admins')
            ->addColumn('session_epoch', 'biginteger', ['signed' => false, 'null' => false, 'default' => 0])
            ->update();

        $this->table('starter_organizations', ['engine' => 'InnoDB', 'signed' => false, 'id' => 'id'])
            ->addColumn('name', 'string', ['limit' => 100, 'null' => false])
            ->addColumn('activated_at', 'datetime', ['null' => true])
            ->addColumn('suspended_at', 'datetime', ['null' => true])
            ->addColumn('archived_at', 'datetime', ['null' => true])
            ->addColumn('subscription_expires_at', 'datetime', ['null' => false])
            ->create();
        $this->table('starter_invitations', ['engine' => 'InnoDB', 'signed' => false, 'id' => 'id'])
            ->addColumn('organization_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('phone', 'string', [
                'limit' => 16, 'encoding' => 'ascii', 'collation' => 'ascii_bin', 'null' => false,
            ])
            ->addColumn('expires_at', 'datetime', ['null' => false])
            ->addColumn('status', 'enum', ['values' => ['pending', 'accepted', 'revoked'], 'null' => false])
            ->addColumn('reserved', 'boolean', ['default' => true, 'null' => false])
            ->addIndex(['phone', 'status', 'expires_at'])
            ->addForeignKey('organization_id', 'starter_organizations', 'id')
            ->create();
    }
}
