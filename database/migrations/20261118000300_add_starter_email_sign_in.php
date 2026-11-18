<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddStarterEmailSignIn extends AbstractMigration
{
    public function change(): void
    {
        $this->table('starter_accounts')
            ->addColumn('email', 'string', ['limit' => 254, 'encoding' => 'ascii', 'collation' => 'ascii_bin', 'null' => true])
            ->addColumn('email_verified_at', 'datetime', ['null' => true])
            ->addColumn('email_epoch', 'biginteger', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('password_hash', 'string', ['limit' => 255, 'encoding' => 'ascii', 'collation' => 'ascii_bin', 'null' => true])
            ->addIndex(['email'], ['unique' => true, 'name' => 'starter_accounts_email_unique'])
            ->update();
    }
}
