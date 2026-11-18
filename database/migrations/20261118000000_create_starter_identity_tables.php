<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;
use Phinx\Migration\IrreversibleMigrationException;

final class CreateStarterIdentityTables extends AbstractMigration
{
    public function up(): void
    {
        $this->table('starter_accounts', ['id' => false, 'primary_key' => ['id'], 'engine' => 'InnoDB'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false, 'null' => false])
            ->addColumn('phone', 'string', ['limit' => 16, 'collation' => 'ascii_bin', 'encoding' => 'ascii', 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['phone'], ['unique' => true])
            ->create();

        $this->table('starter_platform_admins', [
            'id' => false,
            'primary_key' => ['account_id'],
            'engine' => 'InnoDB',
        ])
            ->addColumn('account_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('status', 'enum', ['values' => ['active', 'revoked'], 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['status'])
            ->addForeignKey('account_id', 'starter_accounts', 'id')
            ->create();

        $this->table('starter_platform_guard', ['id' => false, 'primary_key' => ['id'], 'engine' => 'InnoDB'])
            ->addColumn('id', 'integer', ['limit' => MysqlAdapter::INT_TINY, 'signed' => false, 'null' => false])
            ->create();
        $this->table('starter_platform_guard')->insert(['id' => 1])->saveData();

        $this->table('starter_audit_logs', ['id' => false, 'primary_key' => ['id'], 'engine' => 'InnoDB'])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false, 'null' => false])
            ->addColumn('action', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('actor_account_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('target_account_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('metadata', 'json', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->create();
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('Starter identity data must be retained.');
    }
}
