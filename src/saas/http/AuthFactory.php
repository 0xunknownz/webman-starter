<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\http;

use Closure;
use InvalidArgumentException;
use Redis;
use support\Db;
use support\Redis as WebmanRedis;
use Z0x\WebmanStarter\saas\contract\SmsSenderInterface;
use Z0x\WebmanStarter\saas\email\EmailController;
use Z0x\WebmanStarter\saas\email\EmailSenderInterface;
use Z0x\WebmanStarter\saas\email\EmailSignInService;
use Z0x\WebmanStarter\saas\email\EmailVerification;
use Z0x\WebmanStarter\saas\email\IlluminateEmailAccountRepository;
use Z0x\WebmanStarter\saas\email\RedisPasswordLoginLimiter;
use Z0x\WebmanStarter\saas\emailchange\EmailChangeController;
use Z0x\WebmanStarter\saas\emailchange\EmailChangeService;
use Z0x\WebmanStarter\saas\emailchange\IlluminateEmailChangeRepository;
use Z0x\WebmanStarter\saas\infrastructure\IlluminatePlatformAdminRepository;
use Z0x\WebmanStarter\saas\infrastructure\IlluminateSmsLoginRepository;
use Z0x\WebmanStarter\saas\operator\IlluminateOperatorRecoveryRepository;
use Z0x\WebmanStarter\saas\operator\OperatorRecoveryController;
use Z0x\WebmanStarter\saas\operator\OperatorRecoveryEvidenceVerifierInterface;
use Z0x\WebmanStarter\saas\operator\OperatorRecoveryService;
use Z0x\WebmanStarter\saas\platform\IlluminatePlatformAdminMutationRepository;
use Z0x\WebmanStarter\saas\platform\ManagePlatformAdminsService;
use Z0x\WebmanStarter\saas\platform\PlatformAdminController;
use Z0x\WebmanStarter\saas\profile\IlluminateProfileRepository;
use Z0x\WebmanStarter\saas\profile\ProfileController;
use Z0x\WebmanStarter\saas\profile\ProfileService;
use Z0x\WebmanStarter\saas\rebind\IlluminatePhoneChangeRepository;
use Z0x\WebmanStarter\saas\rebind\PhoneChangeController;
use Z0x\WebmanStarter\saas\rebind\PhoneChangeService;
use Z0x\WebmanStarter\saas\recovery\EmailRecoveryController;
use Z0x\WebmanStarter\saas\recovery\EmailRecoveryService;
use Z0x\WebmanStarter\saas\recovery\IlluminateEmailRecoveryRepository;
use Z0x\WebmanStarter\saas\reset\IlluminatePasswordResetRepository;
use Z0x\WebmanStarter\saas\reset\PasswordResetController;
use Z0x\WebmanStarter\saas\reset\PasswordResetService;
use Z0x\WebmanStarter\saas\service\SmsLoginService;
use Z0x\WebmanStarter\saas\session\SessionService;
use Z0x\WebmanStarter\saas\verification\RatePolicy;
use Z0x\WebmanStarter\saas\verification\RedisVerification;
use Z0x\WebmanStarter\Support\Exception\BusinessException;

final class AuthFactory
{
    /** @param Closure():int $clock */
    public function __construct(
        private readonly string $prefix,
        private readonly string $secret,
        private readonly RatePolicy $smsPolicy,
        private readonly SmsSenderInterface $smsSender,
        private readonly Closure $clock,
        private readonly ClientAddress $clientAddress,
        private readonly string $databaseConnection = 'mysql',
        private readonly string $redisConnection = 'starter_auth',
        private readonly ?EmailSenderInterface $emailSender = null,
        private readonly ?RatePolicy $emailPolicy = null,
        private readonly ?OperatorRecoveryEvidenceVerifierInterface $operatorRecoveryVerifier = null,
    ) {
        if (preg_match('/\A[A-Za-z0-9._-]+:[A-Za-z0-9._-]+:?\z/', $prefix) !== 1 || strlen($secret) < 32) {
            throw new InvalidArgumentException('Authentication requires a product:environment Redis prefix and a secret of at least 32 bytes.');
        }
    }

    public function platformAdminController(): PlatformAdminController
    {
        $repository = new IlluminatePlatformAdminMutationRepository(Db::connection($this->databaseConnection));

        return new PlatformAdminController(new ManagePlatformAdminsService($repository));
    }

    public function operatorRecoveryService(): OperatorRecoveryService
    {
        if ($this->operatorRecoveryVerifier === null) {
            throw BusinessException::forbidden();
        }
        $connection = Db::connection($this->databaseConnection);
        $flows = new IlluminatePhoneChangeRepository($connection);
        $verification = new RedisVerification($this->redisClient(), $this->prefix, $this->secret, $this->smsPolicy);
        $changes = new PhoneChangeService($flows, $verification, $this->smsSender);

        return new OperatorRecoveryService(new IlluminateOperatorRecoveryRepository($connection), $flows, $changes,
            $this->operatorRecoveryVerifier);
    }

    public function operatorRecoveryController(): OperatorRecoveryController
    {
        return new OperatorRecoveryController($this->operatorRecoveryService(), $this->clock, $this->clientAddress);
    }

    public function phoneChangeController(): PhoneChangeController
    {
        $repository = new IlluminatePhoneChangeRepository(Db::connection($this->databaseConnection));
        $verification = new RedisVerification($this->redisClient(), $this->prefix, $this->secret, $this->smsPolicy);
        $service = new PhoneChangeService($repository, $verification, $this->smsSender);

        return new PhoneChangeController($service, $this->clock, $this->clientAddress);
    }

    public function profileController(): ProfileController
    {
        $repository = new IlluminateProfileRepository(Db::connection($this->databaseConnection));

        return new ProfileController(new ProfileService($repository));
    }

    public function emailChangeController(): EmailChangeController
    {
        if ($this->emailSender === null || $this->emailPolicy === null) {
            throw BusinessException::notFound();
        }
        $connection = Db::connection($this->databaseConnection);
        $redis = $this->redisClient();
        $service = new EmailChangeService(
            new IlluminateEmailChangeRepository($connection),
            new EmailVerification($redis, $this->prefix, $this->secret, $this->emailPolicy),
            new RedisVerification($redis, $this->prefix, $this->secret, $this->smsPolicy),
            $this->emailSender,
            $this->smsSender,
        );

        return new EmailChangeController($service, $this->clock, $this->clientAddress);
    }

    public function emailRecoveryController(): EmailRecoveryController
    {
        if ($this->emailSender === null || $this->emailPolicy === null) {
            throw BusinessException::notFound();
        }
        $connection = Db::connection($this->databaseConnection);
        $redis = $this->redisClient();
        $flows = new IlluminatePhoneChangeRepository($connection);
        $verification = new RedisVerification($redis, $this->prefix, $this->secret, $this->smsPolicy);
        $phoneChanges = new PhoneChangeService($flows, $verification, $this->smsSender);
        $service = new EmailRecoveryService(
            new IlluminateEmailRecoveryRepository($connection),
            $flows,
            $phoneChanges,
            new EmailVerification($redis, $this->prefix, $this->secret, $this->emailPolicy),
            $this->emailSender,
        );

        return new EmailRecoveryController($service, $this->clock, $this->clientAddress);
    }

    public function passwordResetController(): PasswordResetController
    {
        if ($this->emailSender === null || $this->emailPolicy === null) {
            throw BusinessException::notFound();
        }
        $connection = Db::connection($this->databaseConnection);
        $verification = new EmailVerification($this->redisClient(), $this->prefix, $this->secret, $this->emailPolicy);
        $service = new PasswordResetService(new IlluminatePasswordResetRepository($connection), $verification, $this->emailSender);

        return new PasswordResetController($service, $this->clock, $this->clientAddress);
    }

    public function emailController(): EmailController
    {
        if ($this->emailSender === null || $this->emailPolicy === null) {
            throw BusinessException::notFound();
        }
        $connection = Db::connection($this->databaseConnection);
        $redis = $this->redisClient();
        $sessions = new SessionService($redis, $this->prefix, $this->secret, $connection);
        $service = new EmailSignInService(
            new IlluminateEmailAccountRepository($connection),
            new EmailVerification($redis, $this->prefix, $this->secret, $this->emailPolicy),
            $this->emailSender,
            new RedisPasswordLoginLimiter($redis, $this->prefix, $this->secret),
            $sessions,
        );

        return new EmailController($service, $this->controller(), $this->clock, $this->clientAddress);
    }

    public function controller(): AuthController
    {
        $connection = Db::connection($this->databaseConnection);
        $redis = $this->redisClient();
        $verification = new RedisVerification($redis, $this->prefix, $this->secret, $this->smsPolicy);
        $sessions = new SessionService($redis, $this->prefix, $this->secret, $connection);
        $repository = new IlluminateSmsLoginRepository($connection, new IlluminatePlatformAdminRepository($connection));

        return new AuthController(
            new SmsLoginService($repository, $verification, $this->smsSender, $sessions),
            $sessions,
            $this->clock,
            $this->clientAddress,
        );
    }

    private function redisClient(): Redis
    {
        $redis = WebmanRedis::connection($this->redisConnection)->client();
        if (!$redis instanceof Redis || (string) $redis->getOption(Redis::OPT_PREFIX) !== '') {
            throw new InvalidArgumentException('Authentication requires an unprefixed phpredis connection.');
        }

        return $redis;
    }
}
