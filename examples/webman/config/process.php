<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

declare(strict_types=1);

use support\Log;
use support\Request;
use app\process\Http;

global $argv;

$configuredPort = getenv('WEBMAN_STARTER_HTTP_PORT');
$port = filter_var($configuredPort === false ? '8787' : $configuredPort, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 65535],
]);
if ($port === false) {
    throw new InvalidArgumentException('WEBMAN_STARTER_HTTP_PORT must be between 1 and 65535.');
}

$workers = getenv('WEBMAN_STARTER_HTTP_WORKERS');
$count = filter_var($workers === false ? '1' : $workers, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 32],
]);
if ($count === false) {
    throw new InvalidArgumentException('WEBMAN_STARTER_HTTP_WORKERS must be between 1 and 32.');
}

return [
    'webman' => [
        'handler' => Http::class,
        'listen' => 'http://127.0.0.1:' . $port,
        'count' => $count,
        'user' => '',
        'group' => '',
        'reusePort' => false,
        'eventLoop' => '',
        'context' => [],
        'constructor' => [
            'requestClass' => Request::class,
            'logger' => Log::channel('default'),
            'appPath' => app_path(),
            'publicPath' => public_path()
        ]
    ],
    // File update detection and automatic reload
    'monitor' => [
        'handler' => app\process\Monitor::class,
        'reloadable' => false,
        'constructor' => [
            // Monitor these directories
            'monitorDir' => array_merge([
                app_path(),
                config_path(),
                base_path() . '/process',
                base_path() . '/support',
                base_path() . '/resource',
                base_path() . '/.env',
            ], glob(base_path() . '/plugin/*/app'), glob(base_path() . '/plugin/*/config'), glob(base_path() . '/plugin/*/api')),
            // Files with these suffixes will be monitored
            'monitorExtensions' => [
                'php', 'html', 'htm', 'env'
            ],
            'options' => [
                'enable_file_monitor' => !in_array('-d', $argv) && DIRECTORY_SEPARATOR === '/',
                'enable_memory_monitor' => DIRECTORY_SEPARATOR === '/',
            ]
        ]
    ]
];
