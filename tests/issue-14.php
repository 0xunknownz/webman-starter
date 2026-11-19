<?php

declare(strict_types=1);

namespace StarterAcceptance;

use Z0x\WebmanStarter\saas\infrastructure\IlluminatePlatformAdminRepository;
use Z0x\WebmanStarter\saas\organization\IlluminateOrganizationRepository;
use Z0x\WebmanStarter\saas\organization\SubscriptionWindow;
use Z0x\WebmanStarter\saas\package\IlluminatePackageRepository;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/fixtures.php';

$starts = 1_800_000_000;
$ends = $starts + 3600;
$window = SubscriptionWindow::fromInput('2027-01-15T16:00:00+08:00', '2027-01-15T09:00:00Z');
check($window->startsAt->getTimestamp() === $starts && $window->expiresAt->getTimestamp() === $ends, 'Normalize equivalent RFC 3339 instants');
check($window->statusAt($starts - 1) === 'inactive', 'Before subscription begins');
check($window->statusAt($starts) === 'active', 'Subscription begins at the inclusive start');
check($window->statusAt($ends - 1) === 'active', 'Last active second');
check($window->statusAt($ends) === 'expired', 'Expiry is exclusive');
foreach ([['2027-02-30T08:00:00Z', '2027-03-05T09:00:00Z'], ['2027-01-15T08:00:00', '2027-01-15T09:00:00Z'], ['2027-01-15T08:00:00Z', '2027-01-15T16:00:00+08:00']] as [$start, $end]) {
    try {
        SubscriptionWindow::fromInput($start, $end);
        check(false, 'Invalid window must be rejected');
    } catch (BusinessException $error) {
        check($error->code() === 'subscription_window_invalid' && $error->status() === 422, 'Invalid window contract');
    }
}


function seatRepository14(): array
{
    $connection = (new \Illuminate\Database\Connectors\ConnectionFactory(new \Illuminate\Container\Container()))
        ->make(\Z0x\WebmanStarter\saas\infrastructure\StarterDatabaseConfig::fromEnvironment());

    $packages = new IlluminatePackageRepository($connection, new IlluminatePlatformAdminRepository($connection));

    return [$connection, new IlluminateOrganizationRepository($connection, $packages)];
}

function reserve14(int $organizationId, string $number, int $now): string
{
    [$connection, $repository] = seatRepository14();
    $phone = \Z0x\WebmanStarter\saas\domain\MainlandMobileNumber::fromInput($number);
    try {
        $repository->transaction(static function () use ($connection, $repository, $organizationId, $phone, $now): void {
            $repository->lockAccountByPhone($phone);
            $organization = $repository->find($organizationId, lock: true);
            check($organization !== null, 'Missing organization');
            $repository->assertInvitationCapacity($organization, $phone, $now);
            $connection->table('starter_invitations')->insert([
                'organization_id' => $organizationId,
                'phone' => $phone->e164,
                'status' => 'pending',
                'expires_at' => gmdate('Y-m-d H:i:s', $now + 30),
            ]);
        });
    } catch (BusinessException $error) {
        return $error->code();
    }

    return 'reserved';
}

if (($argv[1] ?? '') === 'reserve') {
    check(fgets(STDIN) === "reserve\n", 'Unexpected worker input');
    echo reserve14((int) $argv[2], $argv[3], $starts), "\n";
    exit;
}

if (($argv[1] ?? '') === 'verify') {
    [$connection, $repository] = seatRepository14();
    $package = (int) $connection->table('starter_packages')->insertGetId(['name' => 'Starter', 'seat_limit' => 1]);
    for ($i = 1; $i <= 4; $i++) {
        $org = $repository->transaction(static fn () => $repository->create("Organization $i", $package, $window));
        check(!$org->activated && !$org->suspended && !$org->archived, 'New organization is pending');
    }
    $first = $connection->table('starter_organizations')->where('name', 'Organization 1')->value('id');
    $connection->table('starter_packages')->where('id', $package)->update(['name' => 'Changed', 'seat_limit' => 2]);
    $snapshot = $repository->find((int) $first);
    check($snapshot?->packageName === 'Starter' && $snapshot->seatLimit === 1, 'Keep original snapshot');
    $utc = '2027-01-15 08:00:00';
    $connection->table('starter_organizations')->where('id', $first)->update(['archived_at' => $utc, 'suspended_at' => $utc]);
    $archived = $repository->find((int) $first);
    check($archived?->archived && $archived->suspended && !$archived->activated, 'Archive keeps pending and suspended');
    $connection->table('starter_organizations')->where('id', $first)->update(['activated_at' => $utc]);
    $archived = $repository->find((int) $first);
    check($archived?->archived && $archived->suspended && $archived->activated, 'Archive keeps activated and suspended');
    check(reserve14((int) $first, '13800138000', $starts) === 'reserved', 'Reserve one seat');
    check(reserve14((int) $first, '+8613800138000', $starts) === 'invitation_already_exists', 'Duplicate precedes capacity');
    check(reserve14((int) $first, '13900139000', $starts) === 'seat_limit_exceeded', 'Enforce snapshot seat limit');
    $second = $connection->table('starter_organizations')->where('name', 'Organization 2')->value('id');
    check(reserve14((int) $second, '13800138000', $starts) === 'reserved', 'Same phone can reserve in another organization');
    check($repository->seatOccupancy((int) $first, $starts) === 1, 'Active reservation counts');
    check($repository->seatOccupancy((int) $first, $starts + 30) === 0, 'Expiry releases the seat without cleanup');
    $id = (int) $connection->table('starter_invitations')->where('organization_id', $first)->value('id');
    try {
        $repository->transaction(static function () use ($connection, $repository, $first, $id, $starts): void {
            $phone = \Z0x\WebmanStarter\saas\domain\MainlandMobileNumber::fromInput('13900139000');
            $repository->lockAccountByPhone($phone);
            $organization = $repository->find((int) $first, lock: true);
            $repository->assertInvitationCapacity($organization, $phone, $starts, replacing: $id);
            $connection->table('starter_invitations')->where('id', $id)->update(['status' => 'revoked', 'reserved' => false]);
            throw new \RuntimeException('Simulated replacement write failure');
        });
    } catch (\RuntimeException $error) {
        check($error->getMessage() === 'Simulated replacement write failure', $error->getMessage());
    }
    check($repository->seatOccupancy((int) $first, $starts) === 1, 'Failed replacement rolls back release');
    $connection->table('starter_invitations')->where('id', $id)->update(['status' => 'revoked', 'reserved' => false]);
    check($repository->seatOccupancy((int) $first, $starts) === 0, 'Revocation immediately releases the seat');
    $phone = \Z0x\WebmanStarter\saas\domain\MainlandMobileNumber::fromInput('13800138000');
    $connection->table('starter_accounts')->insert(['phone' => $phone->e164, 'created_at' => $utc, 'updated_at' => $utc]);
    $repository->transaction(static function () use ($connection, $repository, $second, $phone): void {
        $account = $repository->lockAccountByPhone($phone);
        check($account !== null, 'Missing account fixture');
        $repository->find((int) $second, lock: true);
        $connection->table('starter_invitations')->where('organization_id', $second)->where('status', 'pending')
            ->update(['status' => 'accepted', 'reserved' => false, 'account_id' => $account]);
        $connection->table('starter_memberships')->insert(['organization_id' => $second, 'account_id' => $account, 'role' => 'member']);
    });
    check($repository->seatOccupancy((int) $second, $starts) === 1, 'An accepted invitation converts a seat without double counting');
    check(reserve14((int) $second, $phone->e164, $starts) === 'member_already_exists', 'Member check precedes capacity');
    check($snapshot->subscription->startsAt->getTimestamp() === $starts, 'SQL round trip keeps the start instant');
    exit;
}

$fixtures = new Fixtures();
try {
    $fixtures->startServices();
    $root = dirname(__DIR__);
    $migration = [PHP_BINARY, 'vendor/bin/phinx', 'migrate', '-c', 'phinx.php'];
    $sql = $fixtures->database('qa_org');
    $fixtures->run([...$migration, '-t', '20261118000900'], 'Migrate D1 schema', $root);
    $sql->exec("INSERT INTO starter_organizations (name, subscription_expires_at) VALUES ('Legacy', '2027-01-15 09:00:00')");
    $fixtures->run($migration, 'Migrate D2 from D1', $root);
    $repeat = $fixtures->run($migration, 'Repeat D2 migration', $root);
    check(!str_contains($repeat, 'migrating'), 'Repeat migration must not reapply D2');
    check((int) $sql->query("SELECT COUNT(*) FROM starter_organizations WHERE name='Legacy'")->fetchColumn() === 1,
        'D1 organization must be preserved');
    $fixtures->run([PHP_BINARY, __FILE__, 'verify'], 'Subscription snapshot and seats', $root);
    foreach ([[3, '13900139000', '+8613900139000', 'invitation_already_exists'], [4, '13800138000', '13900139000', 'seat_limit_exceeded']] as [$index, $first, $second, $expected]) {
        $statement = $sql->prepare('SELECT id FROM starter_organizations WHERE name = ?');
        $statement->execute(["Organization $index"]);
        $id = (string) $statement->fetchColumn();
        $workers = [];
        foreach ([$first, $second] as $target) {
            $workers[] = $fixtures->start([PHP_BINARY, __FILE__, 'reserve', $id, $target], pipeInput: true);
        }
        foreach ($workers as $worker) {
            $worker->writeInput("reserve\n");
        }
        $results = [];
        foreach ($workers as $worker) {
            [$status, $output] = $worker->result();
            check($status === 0, "Concurrent reservation failed: $output");
            $results[] = trim($output);
        }
        sort($results);
        $pair = ['reserved', $expected];
        sort($pair);
        check($results === $pair, 'Unexpected concurrent results: ' . implode(', ', $results));
    }
    $fixtures->database('qa_fresh');
    $fixtures->run($migration, 'Migrate fresh schema', $root);
    echo "Issue #14 subscription and MySQL checks passed\n";
} finally {
    $fixtures->close();
}
