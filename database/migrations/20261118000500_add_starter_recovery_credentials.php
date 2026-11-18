<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddStarterRecoveryCredentials extends AbstractMigration
{
    public function change(): void
    {
        $this->table('starter_phone_changes')
            ->addColumn('recovery_email_epoch', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('recovery_token_hash', 'string', [
                'limit' => 64, 'encoding' => 'ascii', 'collation' => 'ascii_bin', 'null' => true,
            ])
            ->update();
    }
}
