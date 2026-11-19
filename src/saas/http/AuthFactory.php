<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\http;

use Closure;
use Illuminate\Database\ConnectionInterface;
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
use Z0x\WebmanStarter\saas\infrastructure\IlluminateOrganizationAudit;
use Z0x\WebmanStarter\saas\infrastructure\IlluminatePlatformAdminRepository;
use Z0x\WebmanStarter\saas\infrastructure\IlluminateSmsLoginRepository;
use Z0x\WebmanStarter\saas\invitation\IlluminateInvitationRepository;
use Z0x\WebmanStarter\saas\invitation\InvitationController;
use Z0x\WebmanStarter\saas\invitation\InvitationLifecycle;
use Z0x\WebmanStarter\saas\invitation\InvitationLink;
use Z0x\WebmanStarter\saas\invitation\ManageInvitationsService;
use Z0x\WebmanStarter\saas\invitationacceptance\AcceptInvitationService;
use Z0x\WebmanStarter\saas\invitationacceptance\IlluminateInvitationAcceptanceRepository;
use Z0x\WebmanStarter\saas\invitationacceptance\IlluminateInvitationVerificationAudit;
use Z0x\WebmanStarter\saas\invitationacceptance\InvitationAcceptanceController;
use Z0x\WebmanStarter\saas\invitationverification\InvitationVerificationService;
use Z0x\WebmanStarter\saas\membership\IlluminateMembershipRepository;
use Z0x\WebmanStarter\saas\membership\MembershipController;
use Z0x\WebmanStarter\saas\membership\MembershipService;
use Z0x\WebmanStarter\saas\operator\IlluminateOperatorRecoveryRepository;
use Z0x\WebmanStarter\saas\operator\OperatorRecoveryController;
use Z0x\WebmanStarter\saas\operator\OperatorRecoveryEvidenceVerifierInterface;
use Z0x\WebmanStarter\saas\operator\OperatorRecoveryService;
use Z0x\WebmanStarter\saas\organization\IlluminateOrganizationRepository;
use Z0x\WebmanStarter\saas\organizationaccess\IlluminateOrganizationMembershipRepository;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationAccessService;
use Z0x\WebmanStarter\saas\organizationplatform\IlluminatePlatformOrganizationRepository;
use Z0x\WebmanStarter\saas\organizationplatform\ManagePlatformOrganizationsService;
use Z0x\WebmanStarter\saas\organizationplatform\PlatformOrganizationController;
use Z0x\WebmanStarter\saas\ownership\IlluminateOwnershipTransferRepository;
use Z0x\WebmanStarter\saas\ownership\OwnershipTransferController;
use Z0x\WebmanStarter\saas\ownership\OwnershipTransferInvalidator;
use Z0x\WebmanStarter\saas\ownership\OwnershipTransferService;
use Z0x\WebmanStarter\saas\package\IlluminatePackageRepository;
use Z0x\WebmanStarter\saas\package\ManagePackagesService;
use Z0x\WebmanStarter\saas\package\PackageController;
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
use Z0x\WebmanStarter\saas\session\IlluminateSessionAccountRepository;
use Z0x\WebmanStarter\saas\session\RedisSessionStore;
use Z0x\WebmanStarter\saas\session\SessionService;
use Z0x\WebmanStarter\saas\subscription\IlluminateSubscriptionRepository;
use Z0x\WebmanStarter\saas\subscription\ManageSubscriptionsService;
use Z0x\WebmanStarter\saas\subscription\SubscriptionController;
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
        private readonly ?string $invitationPageUrl = null,
    ) {
        if (preg_match('/\A[A-Za-z0-9._-]+:[A-Za-z0-9._-]+:?\z/', $prefix) !== 1 || strlen($secret) < 32) {
            throw new InvalidArgumentException('Authentication requires a product:environment Redis prefix and a secret of at least 32 bytes.');
        }
    }

    public function membershipController(): MembershipController
    {
        $connection = Db::connection($this->databaseConnection);
        $audit = new IlluminateOrganizationAudit($connection);
        $transfers = new IlluminateOwnershipTransferRepository($connection);
        $repository = new IlluminateMembershipRepository($connection, new OwnershipTransferInvalidator($transfers, $audit));

        return new MembershipController(new MembershipService(
            new OrganizationAccessService($this->organizationRepository($connection), $repository),
            $this->sessions($connection, $this->redisClient()),
            $repository,
            $audit,
            $this->clock,
        ));
    }

    public function invitationController(): InvitationController
    {
        $connection = Db::connection($this->databaseConnection);
        $organizations = $this->organizationRepository($connection);

        return new InvitationController(new ManageInvitationsService(
            new OrganizationAccessService($organizations, new IlluminateOrganizationMembershipRepository($connection)),
            $this->sessions($connection, $this->redisClient()),
            $this->invitationLifecycle($connection),
            $this->clock,
        ));
    }

    public function platformOrganizationController(): PlatformOrganizationController
    {
        $connection = Db::connection($this->databaseConnection);

        $organizations = $this->organizationRepository($connection);

        return new PlatformOrganizationController(new ManagePlatformOrganizationsService(
            new IlluminatePlatformOrganizationRepository($connection, $organizations),
            new IlluminateInvitationRepository($connection),
            $this->invitationLifecycle($connection, requirePage: false),
            new IlluminateOrganizationAudit($connection),
            $this->sessions($connection, $this->redisClient()),
            $this->clock,
            new OrganizationAccessService($organizations, new IlluminateOrganizationMembershipRepository($connection)),
        ));
    }

    public function packageController(): PackageController
    {
        $connection = Db::connection($this->databaseConnection);

        return new PackageController(new ManagePackagesService(
            $this->packageRepository($connection),
            $this->sessions($connection, $this->redisClient()),
            $this->clock,
        ));
    }

    public function subscriptionController(): SubscriptionController
    {
        $connection = Db::connection($this->databaseConnection);

        $packages = $this->packageRepository($connection);
        $organizations = new IlluminateOrganizationRepository($connection, $packages);

        return new SubscriptionController(new ManageSubscriptionsService(
            new IlluminateSubscriptionRepository($connection, $organizations),
            new IlluminateOrganizationAudit($connection),
            $this->sessions($connection, $this->redisClient()),
            $this->clock,
            $packages,
        ));
    }

    public function invitationAcceptanceController(): InvitationAcceptanceController
    {
        $connection = Db::connection($this->databaseConnection);
        $audit = new IlluminateOrganizationAudit($connection);
        $verification = new InvitationVerificationService(
            new RedisVerification($this->redisClient(), $this->prefix, $this->secret, $this->smsPolicy),
            $this->smsSender,
            new IlluminateInvitationVerificationAudit($connection, $audit),
        );

        return new InvitationAcceptanceController(new AcceptInvitationService(
            $this->organizationRepository($connection),
            $this->invitationLifecycle($connection),
            new IlluminateInvitationRepository($connection),
            new IlluminateInvitationAcceptanceRepository($connection),
            $verification,
            $audit,
            $this->clock,
        ), $this->clientAddress);
    }

    public function ownershipTransferController(): OwnershipTransferController
    {
        $connection = Db::connection($this->databaseConnection);
        $audit = new IlluminateOrganizationAudit($connection);
        $repository = new IlluminateOwnershipTransferRepository($connection);

        return new OwnershipTransferController(new OwnershipTransferService(
            new OrganizationAccessService($this->organizationRepository($connection), new IlluminateOrganizationMembershipRepository($connection)),
            $this->sessions($connection, $this->redisClient()),
            $repository,
            $audit,
            $this->clock,
        ));
    }

    /** @param array<string, list<\Z0x\WebmanStarter\saas\organizationaccess\OrganizationRole>> $permissions */
    public function organizationAccess(array $permissions = []): OrganizationAccessService
    {
        $connection = Db::connection($this->databaseConnection);

        return new OrganizationAccessService($this->organizationRepository($connection),
            new IlluminateOrganizationMembershipRepository($connection), $permissions);
    }

    public function organizationSessions(): SessionService
    {
        return $this->sessions(Db::connection($this->databaseConnection), $this->redisClient());
    }

    private function organizationRepository(ConnectionInterface $connection): IlluminateOrganizationRepository
    {
        return new IlluminateOrganizationRepository($connection, $this->packageRepository($connection));
    }

    private function packageRepository(ConnectionInterface $connection): IlluminatePackageRepository
    {
        return new IlluminatePackageRepository($connection, new IlluminatePlatformAdminRepository($connection));
    }

    private function invitationLifecycle(ConnectionInterface $connection, bool $requirePage = true): InvitationLifecycle
    {
        if ($requirePage && $this->invitationPageUrl === null) {
            throw BusinessException::notFound();
        }

        return new InvitationLifecycle(
            $this->organizationRepository($connection),
            new IlluminateInvitationRepository($connection),
            $this->invitationPageUrl === null ? null : new InvitationLink($this->invitationPageUrl, $this->secret),
            new IlluminateOrganizationAudit($connection),
        );
    }

    public function platformAdminController(): PlatformAdminController
    {
        $connection = Db::connection($this->databaseConnection);
        $repository = new IlluminatePlatformAdminMutationRepository($connection);
        $sessions = $this->sessions($connection, $this->redisClient());

        return new PlatformAdminController(new ManagePlatformAdminsService($repository, $sessions, $this->clock));
    }

    public function operatorRecoveryService(): OperatorRecoveryService
    {
        if ($this->operatorRecoveryVerifier === null) {
            throw BusinessException::forbidden();
        }
        $connection = Db::connection($this->databaseConnection);
        $flows = new IlluminatePhoneChangeRepository($connection);
        $redis = $this->redisClient();
        $verification = new RedisVerification($redis, $this->prefix, $this->secret, $this->smsPolicy);
        $sessions = $this->sessions($connection, $redis);
        $changes = new PhoneChangeService($flows, $verification, $this->smsSender, $sessions);

        return new OperatorRecoveryService(new IlluminateOperatorRecoveryRepository($connection), $flows, $changes,
            $this->operatorRecoveryVerifier, $sessions);
    }

    public function operatorRecoveryController(): OperatorRecoveryController
    {
        return new OperatorRecoveryController($this->operatorRecoveryService(), $this->clock, $this->clientAddress);
    }

    public function phoneChangeController(): PhoneChangeController
    {
        $connection = Db::connection($this->databaseConnection);
        $redis = $this->redisClient();
        $repository = new IlluminatePhoneChangeRepository($connection);
        $verification = new RedisVerification($redis, $this->prefix, $this->secret, $this->smsPolicy);
        $sessions = $this->sessions($connection, $redis);
        $service = new PhoneChangeService($repository, $verification, $this->smsSender, $sessions);

        return new PhoneChangeController($service, $this->clock, $this->clientAddress);
    }

    public function profileController(): ProfileController
    {
        $connection = Db::connection($this->databaseConnection);
        $repository = new IlluminateProfileRepository($connection);
        $sessions = $this->sessions($connection, $this->redisClient());

        return new ProfileController(new ProfileService($repository, $sessions), $this->clock);
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
            $this->sessions($connection, $redis),
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
        $sessions = $this->sessions($connection, $redis);
        $phoneChanges = new PhoneChangeService($flows, $verification, $this->smsSender, $sessions);
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
        $sessions = $this->sessions($connection, $redis);
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
        $sessions = $this->sessions($connection, $redis);
        $repository = new IlluminateSmsLoginRepository($connection, new IlluminatePlatformAdminRepository($connection));

        return new AuthController(
            new SmsLoginService($repository, $verification, $this->smsSender, $sessions),
            $sessions,
            $this->clock,
            $this->clientAddress,
        );
    }

    private function sessions(ConnectionInterface $connection, Redis $redis): SessionService
    {
        return new SessionService(
            new RedisSessionStore($redis),
            $this->prefix,
            $this->secret,
            new IlluminateSessionAccountRepository($connection),
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
