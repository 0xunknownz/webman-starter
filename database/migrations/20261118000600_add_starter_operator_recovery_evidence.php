<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddStarterOperatorRecoveryEvidence extends AbstractMigration
{
    public function change(): void
    {
        $this->table('starter_phone_changes')
            ->addColumn('recovery_reason', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('evidence_channel', 'string', ['limit' => 32, 'null' => true])
            ->update();
    }
}
