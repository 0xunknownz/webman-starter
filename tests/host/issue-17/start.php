<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Psr\Log\NullLogger;
use Webman\App;
use Webman\Config;
use Webman\Http\Request;
use Webman\Route;
use Workerman\Worker;
use Z0x\WebmanStarter\saas\infrastructure\StarterDatabaseConfig;
use Z0x\WebmanStarter\saas\session\IlluminateSessionAccountRepository;
use Z0x\WebmanStarter\saas\session\RedisSessionStore;
use Z0x\WebmanStarter\saas\session\SessionService;

// Start only through fixtures.php, which supplies an isolated data directory and database.
$runtime = getenv('TMPDIR');
if ($runtime === false || !str_starts_with(basename($runtime), 'starter-qa-')
    || getenv('STARTER_DB_DATABASE') !== 'qa_access' || getenv('STARTER_DB_HOST') !== '127.0.0.1'
    || getenv('STARTER_REDIS_HOST') !== '127.0.0.1') {
    throw new RuntimeException('This test host requires disposable fixtures.');
}

define('BASE_PATH', $runtime);
require dirname(__DIR__, 3) . '/vendor/autoload.php';

function issue17Dependencies(string $prefix = 'issue17:qa'): array
{
    $connection = (new ConnectionFactory(new Container()))->make(StarterDatabaseConfig::fromEnvironment(BASE_PATH));
    $redis = new Redis();
    $redis->connect('127.0.0.1', (int) getenv('STARTER_REDIS_PORT'));
    $redis->auth((string) getenv('STARTER_REDIS_PASSWORD'));
    $sessions = new SessionService(new RedisSessionStore($redis), $prefix, (string) getenv('ISSUE17_SECRET'),
        new IlluminateSessionAccountRepository($connection));

    return [$connection, $sessions];
}

if (($argv[1] ?? '') === 'tokens') {
    [, $sessions] = issue17Dependencies();
    [$ownerA, $ownerB, $dual, $starts] = array_map('intval', array_slice($argv, 2));
    $tokens = [];
    foreach (['ownerA' => $ownerA, 'ownerB' => $ownerB, 'dual' => $dual] as $key => $account) {
        $tokens[$key] = $sessions->issue($account, SessionService::PURPOSE_ORGANIZATION, $starts - 10)['access_token'];
    }
    $tokens['platform'] = $sessions->issue($ownerA, SessionService::PURPOSE_PLATFORM, $starts - 10)['access_token'];
    [, $otherProduct] = issue17Dependencies('other17:qa');
    $tokens['otherProduct'] = $otherProduct->issue($ownerA, SessionService::PURPOSE_ORGANIZATION, $starts - 10)['access_token'];
    echo json_encode($tokens, JSON_THROW_ON_ERROR);
    exit;
}

$port = (int) getenv('ISSUE17_PORT');
if ($port < 1) {
    throw new RuntimeException('Missing fixture worker port.');
}
$worker = new Worker('http://127.0.0.1:' . $port);
$worker->name = 'starter-issue-17';
$worker->count = 1;
Worker::$pidFile = BASE_PATH . '/issue-17.pid';
Worker::$logFile = BASE_PATH . '/issue-17-worker.log';

$app = new App(Request::class, new NullLogger(), BASE_PATH . '/app', BASE_PATH . '/public');
$worker->onMessage = [$app, 'onMessage'];
$worker->onWorkerStart = static function (Worker $worker) use ($app): void {
    Config::load(__DIR__, ['start', 'route']);
    Route::load([__DIR__]);
    $app->onWorkerStart($worker);
};
Worker::runAll();
