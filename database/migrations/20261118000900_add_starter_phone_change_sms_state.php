<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddStarterPhoneChangeSmsState extends AbstractMigration
{
    public function up(): void
    {
        $issued = ['default' => false, 'null' => false];
        $this->table('starter_phone_changes')
            ->addColumn('old_sms_issued', 'boolean', $issued)
            ->addColumn('new_sms_issued', 'boolean', $issued)
            ->update();

        // Normal flows start with an old-phone challenge. Any destination proof required its own challenge.
        $this->execute("UPDATE starter_phone_changes SET old_sms_issued = 1 WHERE mode = 'normal'");
        $this->execute('UPDATE starter_phone_changes SET new_sms_issued = 1 WHERE new_verified_until IS NOT NULL');
    }

    public function down(): void
    {
        $this->table('starter_phone_changes')
            ->removeColumn('new_sms_issued')
            ->removeColumn('old_sms_issued')
            ->update();
    }
}
