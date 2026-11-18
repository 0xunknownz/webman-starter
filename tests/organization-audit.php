<?php

declare(strict_types=1);

namespace StarterAcceptance;

use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Z0x\WebmanStarter\saas\infrastructure\IlluminateOrganizationAudit;
use Z0x\WebmanStarter\saas\infrastructure\IlluminatePlatformAdminRepository;
use Z0x\WebmanStarter\saas\infrastructure\StarterDatabaseConfig;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/fixtures.php';

if (($argv[1] ?? null) === 'verify') {
    $db = (new ConnectionFactory(new Container()))->make(StarterDatabaseConfig::fromEnvironment());
    $organizations = $db->table('starter_organizations');
    $orgId = (int) $organizations->insertGetId(['name' => '甲', 'subscription_expires_at' => '2027-01-16 09:00:00']);
    $now = 1800000000;
    $audit = new IlluminateOrganizationAudit($db);
    $db->transaction(static fn () => $audit->append($orgId, 'invitation_updated', 12, null, ['phone_masked' => '***8000'], $now));
    (new IlluminatePlatformAdminRepository($db))->appendAudit('phone_changed', 13, 12, [], gmdate('Y-m-d H:i:s', $now));
    $events = $db->table('starter_audit_logs')->where('organization_id', $orgId)->get();
    check(count($events) === 1 && $events[0]->action === 'invitation_updated', 'Organization scope separates identity events');
    check((int) $events[0]->actor_account_id === 12 && $events[0]->target_account_id === null
        && $events[0]->metadata === '{"phone_masked": "***8000"}', 'Audit preserves redacted payload and actor');

    $db->statement("CREATE TRIGGER reject_audit_insert BEFORE INSERT ON starter_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000'");
    try {
        $db->transaction(static function () use ($db, $orgId, $audit, $now): void {
            $db->table('starter_organizations')->where('id', $orgId)->update(['name' => '乙']);
            $audit->append($orgId, 'organization_renamed', 12, null, [], $now);
        });
        check(false, 'Audit writes must fail closed');
    } catch (BusinessException $error) {
        check($error->code() === 'audit_unavailable', 'Audit failure returns a closed business code');
    }
    check($organizations->where('id', $orgId)->value('name') === '甲', 'Roll back the organization mutation with failed audit');
    echo "PASS organization audit scope and transaction rollback\n";
    exit;
}

$fixtures = new Fixtures();
try {
    $fixtures->startServices();
    $fixtures->database('qa_audit');
    $root = dirname(__DIR__);
    $fixtures->run([PHP_BINARY, 'vendor/bin/phinx', 'migrate', '-c', 'phinx.php'], 'Migrate audit scope', $root);
    echo $fixtures->run([PHP_BINARY, __FILE__, 'verify'], 'Verify audit scope and atomicity', $root);
} finally {
    $fixtures->close();
}
