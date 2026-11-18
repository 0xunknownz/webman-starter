<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddStarterDisplayName extends AbstractMigration
{
    public function change(): void
    {
        $this->table('starter_accounts')
            ->addColumn('display_name', 'string', [
                'limit' => 50,
                'null' => true,
                'encoding' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
            ])
            ->update();
    }
}
