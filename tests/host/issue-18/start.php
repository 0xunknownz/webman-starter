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

$runtime = getenv('TMPDIR');
if ($runtime === false || !str_starts_with(basename($runtime), 'starter-qa-')
    || getenv('STARTER_DB_DATABASE') !== 'qa_invitation' || getenv('STARTER_DB_HOST') !== '127.0.0.1'
    || getenv('STARTER_REDIS_HOST') !== '127.0.0.1') {
    throw new RuntimeException('This test host requires disposable fixtures.');
}

define('BASE_PATH', $runtime);
require dirname(__DIR__, 3) . '/vendor/autoload.php';

function issue18Dependencies(): array
{
    $connection = (new ConnectionFactory(new Container()))->make(StarterDatabaseConfig::fromEnvironment(BASE_PATH));
    $redis = new Redis();
    $redis->connect('127.0.0.1', (int) getenv('STARTER_REDIS_PORT'));
    $redis->auth((string) getenv('STARTER_REDIS_PASSWORD'));
    $sessions = new SessionService(new RedisSessionStore($redis), 'fixture18:qa', (string) getenv('ISSUE18_SECRET'),
        new IlluminateSessionAccountRepository($connection));

    return [$connection, $sessions, $redis];
}

if (($argv[1] ?? '') === 'tokens') {
    [, $sessions] = issue18Dependencies();
    $config = json_decode(file_get_contents('php://stdin'), true, flags: JSON_THROW_ON_ERROR);
    $tokens = [];
    foreach ($config as $key => $accountId) {
        $tokens[$key] = $sessions->issue($accountId, $key === 'platform' ? SessionService::PURPOSE_PLATFORM
            : SessionService::PURPOSE_ORGANIZATION, (int) getenv('ISSUE18_NOW'))['access_token'];
    }
    echo json_encode($tokens, JSON_THROW_ON_ERROR);
    exit;
}

$port = (int) getenv('ISSUE18_PORT');
if ($port < 1) {
    throw new RuntimeException('Missing fixture worker port.');
}
$worker = new Worker('http://127.0.0.1:' . $port);
$worker->name = 'starter-issue-18';
$worker->count = 1;
Worker::$pidFile = BASE_PATH . '/issue-18-' . $port . '.pid';
Worker::$logFile = BASE_PATH . '/issue-18-' . $port . '.log';
$app = new App(Request::class, new NullLogger(), BASE_PATH . '/app', BASE_PATH . '/public');
$worker->onMessage = [$app, 'onMessage'];
$worker->onWorkerStart = static function (Worker $worker) use ($app): void {
    Config::load(__DIR__, ['start', 'route']);
    Route::load([__DIR__]);
    $app->onWorkerStart($worker);
};
Worker::runAll();
