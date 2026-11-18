<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddStarterRetainedSession extends AbstractMigration
{
    public function change(): void
    {
        $this->table('starter_accounts')
            ->addColumn('retained_session_id', 'string', ['limit' => 64, 'encoding' => 'ascii', 'collation' => 'ascii_bin', 'null' => true])
            ->addColumn('retained_session_epoch', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('retained_at_epoch', 'biginteger', ['signed' => false, 'null' => true])
            ->update();
    }
}
