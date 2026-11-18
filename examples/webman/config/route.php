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

use Z0x\WebmanStarter\saas\http\AuthFactory;
use Z0x\WebmanStarter\saas\http\Routes;

if (getenv('WEBMAN_STARTER_ROUTES_ENABLED') === '1') {
    $prefix = getenv('WEBMAN_STARTER_ROUTE_PREFIX');
    $settings = config('starter', []);
    $auth = $settings === [] ? null : new AuthFactory(
        $settings['prefix'],
        $settings['secret'],
        $settings['sms_policy'],
        $settings['sms_sender'],
        $settings['clock'],
        $settings['client_address'],
        emailSender: $settings['email_sender'],
        emailPolicy: $settings['email_policy'],
        operatorRecoveryVerifier: $settings['operator_recovery_verifier'],
    );
    Routes::register($prefix === false ? Routes::DEFAULT_PREFIX : $prefix, $auth);
}
