<?php

declare(strict_types=1);

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;
use Webman\Route;
use Z0x\WebmanStarter\saas\contract\SmsSenderInterface;
use Z0x\WebmanStarter\saas\domain\MainlandMobileNumber;
use Z0x\WebmanStarter\saas\domain\SubmissionStatus;
use Z0x\WebmanStarter\saas\http\SafeErrorMiddleware;
use Z0x\WebmanStarter\saas\infrastructure\IlluminateOrganizationAudit;
use Z0x\WebmanStarter\saas\invitation\IlluminateInvitationRepository;
use Z0x\WebmanStarter\saas\invitation\InvitationController;
use Z0x\WebmanStarter\saas\invitation\InvitationLifecycle;
use Z0x\WebmanStarter\saas\invitation\InvitationLink;
use Z0x\WebmanStarter\saas\invitation\InvitationRoutes;
use Z0x\WebmanStarter\saas\invitation\ManageInvitationsService;
use Z0x\WebmanStarter\saas\invitationverification\InvitationVerificationService;
use Z0x\WebmanStarter\saas\invitationverification\InvitationVerificationAuditInterface;
use Z0x\WebmanStarter\saas\organization\IlluminateOrganizationRepository;
use Z0x\WebmanStarter\saas\organizationaccess\IlluminateOrganizationMembershipRepository;
use Z0x\WebmanStarter\saas\organizationaccess\OrganizationAccessService;
use Z0x\WebmanStarter\saas\session\SessionService;
use Z0x\WebmanStarter\saas\verification\RatePolicy;
use Z0x\WebmanStarter\saas\verification\RedisVerification;
use Z0x\WebmanStarter\Support\Exception\BusinessException;
use Z0x\WebmanStarter\Support\Http\ApiResponse;

[$connection, $sessions, $redis] = issue18Dependencies();
$now = (int) getenv('ISSUE18_NOW');
$organizations = new IlluminateOrganizationRepository($connection);
$membership = new IlluminateOrganizationMembershipRepository($connection);
$invitations = new IlluminateInvitationRepository($connection);
$links = new InvitationLink((string) getenv('ISSUE18_PAGE'), (string) getenv('ISSUE18_SECRET'));
$lifecycle = new InvitationLifecycle($organizations, $invitations, $links, new IlluminateOrganizationAudit($connection));
$service = new ManageInvitationsService(new OrganizationAccessService($organizations, $membership), $sessions, $lifecycle, static fn (): int => $now);
$orgAuth = new class ($sessions, $now) implements MiddlewareInterface {
    public function __construct(private readonly SessionService $sessions, private readonly int $now)
    {
    }

    public function process(Request $request, callable $handler): Response
    {
        $header = $request->header('authorization', '');
        if (preg_match('/\ABearer (\S+)\z/', $header, $matches) !== 1) {
            throw BusinessException::unauthenticated();
        }
        $request->starterIdentity = $this->sessions->authenticate($matches[1], SessionService::PURPOSE_ORGANIZATION, $this->now);

        return $handler($request);
    }
};
Route::disableDefaultRoute();
Route::get('/fixture/health', static fn (Request $request): Response => ApiResponse::success(request: $request));
Route::group('/saas', static function () use ($service, $orgAuth): void {
    InvitationRoutes::register(static fn (): InvitationController => new InvitationController($service), $orgAuth);
})->middleware(new SafeErrorMiddleware());

// Proof harness only. It exercises the contract #20 will use without exposing a production acceptance endpoint.
$verification = new RedisVerification($redis, 'fixture18:qa', (string) getenv('ISSUE18_SECRET'), new RatePolicy());
Route::post('/fixture/invitation/proof', static function (Request $request) use ($lifecycle, $verification, $now): Response {
    $invitation = $lifecycle->resolve($request->post('token'));
    $invitation->requireActive($now);
    $sender = new class implements SmsSenderInterface {
        public string $code;

        public function send(MainlandMobileNumber $phone, string $purpose, string $code): SubmissionStatus
        {
            $this->code = $code;

            return SubmissionStatus::Accepted;
        }
    };
    $audit = new class implements InvitationVerificationAuditInterface {
        public function record(string $action, int $invitationId, string $maskedPhone, int $now): void
        {
        }
    };
    $proof = new InvitationVerificationService($verification, $sender, $audit);
    $credential = $request->post('credential');
    if ($credential !== null) {
        $proof->requireCredential($invitation->id, $invitation->phone, $credential, $now);

        return ApiResponse::success(request: $request);
    }
    $challenge = $proof->send($invitation->id, $invitation->phone, '127.0.0.1', $now);

    return ApiResponse::success($proof->verify($invitation->id, $invitation->phone, $challenge, $sender->code, $now), request: $request);
})->middleware(new SafeErrorMiddleware());
