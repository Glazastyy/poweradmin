<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Poweradmin\Application\Controller;

use Exception;
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Application\Service\MailService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserMfa;
use Poweradmin\Domain\Service\AuthenticationService;
use Poweradmin\Domain\Service\MfaService;
use Poweradmin\Domain\Service\MfaSessionManager;
use Poweradmin\Domain\Service\PasskeyService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Repository\DbUserMfaRepository;
use Poweradmin\Infrastructure\Repository\DbUserPasskeyRepository;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;

class MfaPasskeyController extends BaseController
{
    private PasskeyService $passkeyService;
    private MfaService $mfaService;
    private CsrfTokenService $csrfTokenService;
    private UserContextService $userContextService;
    private LegacyLogger $auditLogger;
    private IpAddressRetriever $ipAddressRetriever;

    public function __construct(array $request)
    {
        parent::__construct($request, false);

        $this->passkeyService = new PasskeyService(new DbUserPasskeyRepository($this->db, $this->config), $this->config);
        $userMfaRepository = new DbUserMfaRepository($this->db, $this->config);
        $this->mfaService = new MfaService($userMfaRepository, $this->config, new MailService($this->config), null, $this->createUserTimezoneService());
        $this->csrfTokenService = new CsrfTokenService();
        $this->userContextService = new UserContextService();
        $this->auditLogger = new LegacyLogger($this->db);
        $this->ipAddressRetriever = new IpAddressRetriever($_SERVER);
    }

    public function run(): void
    {
        if (!$this->config->get('security', 'mfa.enabled', false)) {
            $this->json(['success' => false, 'message' => _('MFA is not enabled on this system.')], 403);
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

        try {
            if (str_ends_with($path, '/register/options')) {
                $this->registerOptions();
            } elseif (str_ends_with($path, '/register')) {
                $this->register();
            } elseif (str_ends_with($path, '/verify/options')) {
                $this->verifyOptions();
            } elseif (str_ends_with($path, '/verify')) {
                $this->verify();
            } elseif (str_ends_with($path, '/delete')) {
                $this->delete();
            } else {
                $this->json(['success' => false, 'message' => _('Unknown passkey action.')], 404);
            }
        } catch (Exception $e) {
            $this->logger->warning('[MfaPasskeyController] Passkey operation failed: {error}', ['error' => $e->getMessage()]);
            $this->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    private function registerOptions(): void
    {
        $userId = $this->requireLoggedInUserId();
        $username = $this->userContextService->getLoggedInUsername() ?? 'user' . $userId;
        $displayName = $this->userContextService->getDisplayName() ?? $username;

        $this->json($this->passkeyService->getCreateOptions($userId, $username, $displayName));
    }

    private function register(): void
    {
        $userId = $this->requireLoggedInUserId();
        $this->requireCsrfToken();
        $payload = $this->readJsonBody();
        $name = trim((string) ($payload['name'] ?? ''));

        $this->passkeyService->register($userId, $payload, $name);

        $userMfa = $this->mfaService->getOrCreateUserMfa($userId);
        if (!$userMfa) {
            throw new \RuntimeException(_('Failed to create MFA record.'));
        }
        $userMfa->setSecret(null);
        $userMfa->setType(UserMfa::TYPE_PASSKEY);
        $userMfa->enable();
        if ($userMfa->getRecoveryCodesAsArray() === []) {
            $userMfa->generateRecoveryCodes(
                (int) $this->config->get('security', 'mfa.recovery_codes', 8),
                (int) $this->config->get('security', 'mfa.recovery_code_length', 10)
            );
        }
        $this->mfaService->saveUserMfa($userMfa);

        $this->auditLogger->logInfo(sprintf(
            'client_ip:%s user:%s operation:mfa_enable mfa_type:passkey',
            $this->ipAddressRetriever->getClientIp(),
            $this->userContextService->getLoggedInUsername()
        ));

        $this->json(['success' => true, 'message' => _('Passkey registered successfully.')]);
    }

    private function verifyOptions(): void
    {
        $userId = $this->requirePendingMfaUserId();
        $this->json($this->passkeyService->getVerifyOptions($userId));
    }

    private function verify(): void
    {
        $userId = $this->requirePendingMfaUserId();
        $this->passkeyService->verify($userId, $this->readJsonBody());
        $this->promotePendingSession();
        MfaSessionManager::setMfaVerified();

        $this->auditLogger->logInfo(sprintf(
            'client_ip:%s user:%s operation:mfa_verify mfa_type:passkey',
            $this->ipAddressRetriever->getClientIp(),
            $this->userContextService->getLoggedInUsername() ?? $_SESSION['userlogin'] ?? 'unknown'
        ));

        $redirectUrl = AuthenticationService::consumePostLoginRedirect($this->config->get('interface', 'base_url_prefix', ''));
        $this->json(['success' => true, 'redirect' => $redirectUrl]);
    }

    private function delete(): void
    {
        $userId = $this->requireLoggedInUserId();
        $this->requireCsrfToken();
        $payload = $this->readJsonBody();
        $id = (int) ($payload['id'] ?? 0);
        if ($id <= 0) {
            throw new \RuntimeException(_('Invalid passkey.'));
        }

        $this->passkeyService->deleteUserPasskey($id, $userId);
        if ($this->passkeyService->getUserPasskeys($userId) === [] && $this->mfaService->getMfaType($userId) === UserMfa::TYPE_PASSKEY) {
            $this->mfaService->disableMfa($userId);
        }

        $this->json(['success' => true, 'message' => _('Passkey removed.')]);
    }

    private function requireLoggedInUserId(): int
    {
        $userId = $this->userContextService->getLoggedInUserId() ?? 0;
        if ($userId <= 0) {
            $this->json(['success' => false, 'message' => _('You must be logged in.')], 401);
        }

        return $userId;
    }

    private function requirePendingMfaUserId(): int
    {
        $userId = $this->userContextService->getLoggedInUserId() ?? $this->userContextService->getSessionData('pending_userid') ?? 0;
        if ($userId <= 0 || !$this->userContextService->hasSessionData('mfa_required')) {
            $this->json(['success' => false, 'message' => _('MFA verification is not pending.')], 401);
        }

        return (int) $userId;
    }

    private function promotePendingSession(): void
    {
        foreach ([
            'pending_userid' => 'userid',
            'pending_name' => 'name',
            'pending_email' => 'email',
            'pending_auth_used' => 'auth_used',
            'pending_auth_method_used' => 'auth_method_used',
            'pending_oidc_provider' => 'oidc_provider',
            'pending_oidc_id_token' => 'oidc_id_token',
            'pending_oauth_avatar_url' => 'oauth_avatar_url',
            'pending_saml_provider' => 'saml_provider',
            'pending_saml_name_id' => 'saml_name_id',
            'pending_saml_session_index' => 'saml_session_index',
        ] as $pending => $actual) {
            if ($this->userContextService->hasSessionData($pending)) {
                $this->userContextService->setSessionData($actual, $this->userContextService->getSessionData($pending));
                $this->userContextService->unsetSessionData($pending);
            }
        }

        if ($this->userContextService->hasSessionData('oidc_provider')) {
            $this->userContextService->setSessionData('oidc_authenticated', true);
        }
        if ($this->userContextService->hasSessionData('saml_provider')) {
            $this->userContextService->setSessionData('saml_authenticated', true);
        }
    }

    private function readJsonBody(): array
    {
        $body = file_get_contents('php://input') ?: '';
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException(_('Invalid request body.'));
        }

        return $data;
    }

    private function requireCsrfToken(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!$this->csrfTokenService->validateToken($token)) {
            $this->json(['success' => false, 'message' => _('Invalid security token. Please try again.')], 403);
        }
    }

    private function json(mixed $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }
}
