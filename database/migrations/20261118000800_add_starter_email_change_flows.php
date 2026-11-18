<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddStarterEmailChangeFlows extends AbstractMigration
{
    public function change(): void
    {
        $epoch = ['signed' => false, 'null' => false];
        $this->table('starter_email_change_flows', ['id' => false, 'primary_key' => ['id'], 'engine' => 'InnoDB'])
            ->addColumn('id', 'string', ['limit' => 32, 'encoding' => 'ascii', 'collation' => 'ascii_bin', 'null' => false])
            ->addColumn('account_id', 'biginteger', $epoch)
            ->addColumn('session_id', 'string', ['limit' => 64, 'encoding' => 'ascii', 'collation' => 'ascii_bin', 'null' => false])
            ->addColumn('session_epoch', 'biginteger', $epoch)
            ->addColumn('email_epoch', 'biginteger', $epoch)
            ->addColumn('phone_epoch', 'biginteger', $epoch)
            ->addColumn('new_email', 'string', ['limit' => 254, 'encoding' => 'ascii', 'collation' => 'ascii_bin', 'null' => false])
            ->addColumn('channel', 'enum', ['values' => ['email', 'sms'], 'null' => false])
            ->addColumn('source_verified_until', 'datetime', ['null' => true])
            ->addColumn('new_verified_until', 'datetime', ['null' => true])
            ->addColumn('completed_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addForeignKey('account_id', 'starter_accounts', 'id')
            ->create();
    }
}
