<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class AddStarterInvitationLinks extends AbstractMigration
{
    public function up(): void
    {
        // Existing D1 rows have no public link. They still participate in seat and phone-change rules.
        $this->table('starter_invitations')
            ->addColumn('selector', 'string', [
                'limit' => 32, 'encoding' => 'ascii', 'collation' => 'ascii_bin', 'null' => true,
            ])
            ->addColumn('role', 'enum', ['values' => ['member', 'owner'], 'default' => 'member', 'null' => false])
            ->addColumn('replaced_at', 'datetime', ['null' => true])
            ->addIndex(['selector'], ['name' => 'starter_invites_selector_unique', 'unique' => true])
            ->update();
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Starter invitation links and replacement history must be retained.');
    }
}
