<?php

declare(strict_types=1);

namespace StarterAcceptance;

use PDO;
use PDOException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Redis;
use RedisException;
use RuntimeException;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class Process
{
    /** @var resource|null */
    private $handle;
    private ?int $exitCode = null;

    /** @var resource|null */
    private $input = null;

    /** @param list<string> $command @param array<string, string> $env */
    public function __construct(array $command, string $cwd, array $env, private readonly string $log, bool $pipeInput = false)
    {
        $this->handle = proc_open($command, [
            0 => $pipeInput ? ['pipe', 'r'] : ['file', '/dev/null', 'r'],
            1 => ['file', $log, 'w'],
            2 => ['redirect', 1],
        ], $pipes, $cwd, $env);
        check(is_resource($this->handle), "Could not start {$command[0]}");
        $this->input = $pipes[0] ?? null;
    }

    public function writeInput(string $input): void
    {
        check(is_resource($this->input), 'Process stdin is unavailable');
        try {
            check(fwrite($this->input, $input) === strlen($input), 'Could not write complete process input');
        } finally {
            $this->closeInput();
        }
    }

    private function closeInput(): void
    {
        if (is_resource($this->input)) {
            fclose($this->input);
            $this->input = null;
        }
    }

    public function running(): bool
    {
        if ($this->exitCode !== null) {
            return false;
        }
        $status = proc_get_status($this->handle);
        if (!$status['running']) {
            $this->exitCode = $status['exitcode'];
            $this->closeInput();
            $closed = proc_close($this->handle);
            $this->handle = null;
            if ($this->exitCode === -1) {
                $this->exitCode = $closed;
            }
        }

        return $status['running'];
    }

    /** @return array{int, string} */
    public function result(int $timeout = 20): array
    {
        $deadline = microtime(true) + $timeout;
        while ($this->running()) {
            check(microtime(true) < $deadline, "Process timed out. Log: {$this->output()}");
            usleep(25_000);
        }

        return [$this->exitCode, $this->output()];
    }

    public function output(): string
    {
        // The last part of a command log usually contains the actionable error.
        clearstatcache(true, $this->log);
        $offset = max(0, (int) filesize($this->log) - 12_000);

        return (string) file_get_contents($this->log, false, null, $offset);
    }

    public function stop(): void
    {
        if ($this->running()) {
            proc_terminate($this->handle, SIGTERM);
            try {
                $this->result(8);
            } catch (RuntimeException) {
                proc_terminate($this->handle, SIGKILL);
                $this->result(2);
            }
        }
    }
}

final class Fixtures
{
    public readonly string $dir;
    public readonly string $host;
    public readonly string $sqlLog;
    /** @var array<string, string> */
    private array $env;
    /** @var list<Process> */
    private array $processes = [];
    private PDO $rootDb;
    private string $dbPassword;
    private int $mysqlPort;
    /** @var list<int> */
    private array $ports = [];

    public function __construct()
    {
        $this->dir = sys_get_temp_dir() . '/starter-qa-' . bin2hex(random_bytes(5));
        check(mkdir($this->dir, 0700), 'Could not create disposable test directory');
        $this->host = "$this->dir/host";
        $this->sqlLog = "$this->dir/mysql-queries.log";
        $this->dbPassword = bin2hex(random_bytes(16));
        $this->env = [
            'PATH' => (string) getenv('PATH'),
            'HOME' => (string) getenv('HOME'),
            'TMPDIR' => $this->dir,
            'COMPOSER_HOME' => "$this->dir/composer-home",
            'COMPOSER_CACHE_DIR' => getenv('COMPOSER_CACHE_DIR') ?: match (PHP_OS_FAMILY) {
                'Darwin' => getenv('HOME') . '/Library/Caches/composer',
                default => (getenv('XDG_CACHE_HOME') ?: getenv('HOME') . '/.cache') . '/composer',
            },
            'COMPOSER_CACHE_READ_ONLY' => '1',
        ];
    }

    public function startServices(): void
    {
        $socket = "$this->dir/mysql.sock";
        $data = "$this->dir/mysql";
        $common = ['mysqld', '--no-defaults', "--datadir=$data", "--tmpdir=$this->dir"];
        $this->run([...$common, '--initialize-insecure'], 'initialize MySQL', timeout: 60);
        $this->mysqlPort = $this->port();
        $mysql = $this->start([
            ...$common, "--socket=$socket", "--port=$this->mysqlPort", '--bind-address=127.0.0.1',
            '--mysqlx=OFF', '--skip-log-bin', "--pid-file=$this->dir/mysql.pid", '--general-log=ON',
            "--general-log-file=$this->sqlLog",
        ]);
        $this->ready($mysql, function () use ($socket): bool {
            if (!file_exists($socket)) {
                return false;
            }
            try {
                $this->rootDb = new PDO("mysql:unix_socket=$socket;charset=utf8mb4", 'root', '', [
                    PDO::ATTR_TIMEOUT => 1,
                ]);

                return true;
            } catch (PDOException) {
                return false;
            }
        }, 'MySQL');
        $this->rootDb->exec("CREATE USER 'starter_qa'@'127.0.0.1' IDENTIFIED BY '$this->dbPassword'");

        $port = $this->port();
        $password = bin2hex(random_bytes(16));
        $redis = $this->start([
            'redis-server', '--bind', '127.0.0.1', '--port', (string) $port, '--dir', $this->dir,
            '--daemonize', 'no', '--save', '', '--appendonly', 'no', '--requirepass', $password,
        ]);
        $this->ready($redis, static function () use ($port, $password): bool {
            $client = new Redis();
            try {
                return @$client->connect('127.0.0.1', $port, 0.2)
                    && $client->auth($password) && pingSucceeded($client->ping());
            } catch (RedisException) {
                return false;
            }
        }, 'Redis');
        $this->env += [
            'STARTER_REDIS_HOST' => '127.0.0.1',
            'STARTER_REDIS_PORT' => (string) $port,
            'STARTER_REDIS_PASSWORD' => $password,
            'STARTER_REDIS_PREFIX' => 'qa01:',
        ];
        printf("Services: MySQL %s, Redis %s\n", $this->rootDb->getAttribute(PDO::ATTR_SERVER_VERSION), $this->redisVersion());
    }

    private function redisVersion(): string
    {
        $client = new Redis();
        $client->connect('127.0.0.1', (int) $this->env['STARTER_REDIS_PORT']);
        $client->auth($this->env['STARTER_REDIS_PASSWORD']);

        return $client->info('server')['redis_version'];
    }

    public function database(string $name, bool $create = true): PDO
    {
        check(preg_match('/\Aqa_[a-z]+\z/', $name) === 1, 'Unexpected fixture database name');
        if ($create) {
            $this->rootDb->exec("CREATE DATABASE $name CHARACTER SET utf8mb4");
            $this->rootDb->exec("GRANT ALL PRIVILEGES ON $name.* TO 'starter_qa'@'127.0.0.1'");
        }
        $this->env = [
            'STARTER_DB_HOST' => '127.0.0.1',
            'STARTER_DB_PORT' => (string) $this->mysqlPort,
            'STARTER_DB_DATABASE' => $name,
            'STARTER_DB_USERNAME' => 'starter_qa',
            'STARTER_DB_PASSWORD' => $this->dbPassword,
        ] + $this->env;

        return new PDO("mysql:host=127.0.0.1;port=$this->mysqlPort;dbname=$name;charset=utf8mb4", 'starter_qa', $this->dbPassword);
    }

    public function port(): int
    {
        do {
            $receiver = stream_socket_server('tcp://127.0.0.1:0');
            check(is_resource($receiver), 'Could not allocate a loopback TCP port');
            $address = stream_socket_get_name($receiver, false);
            fclose($receiver);
            $port = (int) substr(strrchr($address, ':'), 1);
        } while (in_array($port, $this->ports, true));
        $this->ports[] = $port;

        return $port;
    }

    /** @param list<string> $command @param array<string, string> $env */
    public function start(array $command, ?string $cwd = null, array $env = [], bool $pipeInput = false): Process
    {
        $log = $this->dir . '/process-' . count($this->processes) . '.log';
        $process = new Process($command, $cwd ?? $this->dir, $env + $this->env, $log, $pipeInput);
        $this->processes[] = $process;

        return $process;
    }

    /** @param list<string> $command */
    public function run(array $command, string $label, ?string $cwd = null, int $timeout = 20): string
    {
        [$code, $output] = $this->start($command, $cwd)->result($timeout);
        check($code === 0, "$label exited $code\n$output");

        return $output;
    }

    public function ready(Process $process, callable $isReady, string $label): void
    {
        $deadline = microtime(true) + 20;
        while ($process->running() && microtime(true) < $deadline) {
            if ($isReady()) {
                check($process->running(), "$label stopped during startup\n{$process->output()}");

                return;
            }
            usleep(50_000);
        }
        throw new RuntimeException("$label did not become ready\n{$process->output()}");
    }

    public function close(): void
    {
        foreach (array_reverse($this->processes) as $process) {
            $process->stop();
        }
        // This directory was created exclusively by this run. Never follow symlinks.
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            $path = $entry->getPathname();
            $entry->isDir() && !$entry->isLink() ? rmdir($path) : unlink($path);
        }
        rmdir($this->dir);
    }
}

function pingSucceeded(mixed $result): bool
{
    return in_array($result, [true, 'PONG', '+PONG'], true);
}

/** @param list<string> $excluded */
function copyTree(string $source, string $target, array $excluded = []): void
{
    if (is_file($source)) {
        check(copy($source, $target), "Could not copy $source");

        return;
    }
    check(is_dir($source) && mkdir($target, 0700), "Could not create a copy of $source");
    foreach (new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS) as $entry) {
        $name = $entry->getFilename();
        if (!$entry->isLink() && !in_array($name, $excluded, true)) {
            copyTree($entry->getPathname(), "$target/$name", $excluded);
        }
    }
}
