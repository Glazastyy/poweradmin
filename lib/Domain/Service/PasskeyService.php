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

namespace Poweradmin\Domain\Service;

use lbuchs\WebAuthn\WebAuthn;
use Poweradmin\Domain\Model\UserPasskey;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbUserPasskeyRepository;

class PasskeyService
{
    public const REGISTER_CHALLENGE = 'passkey_register_challenge';
    public const VERIFY_CHALLENGE = 'passkey_verify_challenge';

    public function __construct(
        private readonly DbUserPasskeyRepository $passkeyRepository,
        private readonly ConfigurationManager $config,
    ) {
    }

    public function getCreateOptions(int $userId, string $username, string $displayName): object
    {
        $webAuthn = $this->createWebAuthn();
        $excludeIds = array_map(
            fn (UserPasskey $passkey): string => $passkey->getCredentialIdBinary(),
            $this->passkeyRepository->findByUserId($userId)
        );
        $options = $webAuthn->getCreateArgs(
            (string) $userId,
            $username,
            $displayName !== '' ? $displayName : $username,
            120,
            'preferred',
            'preferred',
            null,
            $excludeIds
        );

        $_SESSION[self::REGISTER_CHALLENGE] = base64_encode($webAuthn->getChallenge()->getBinaryString());

        return $options;
    }

    public function register(int $userId, array $payload, string $name): UserPasskey
    {
        $challenge = base64_decode($_SESSION[self::REGISTER_CHALLENGE] ?? '', true);
        if ($challenge === false || $challenge === '') {
            throw new \RuntimeException(_('Passkey registration expired. Please try again.'));
        }

        $webAuthn = $this->createWebAuthn();
        $data = $webAuthn->processCreate(
            base64_decode((string) ($payload['clientDataJSON'] ?? ''), true) ?: '',
            base64_decode((string) ($payload['attestationObject'] ?? ''), true) ?: '',
            $challenge,
            false,
            true,
            false,
            false
        );

        unset($_SESSION[self::REGISTER_CHALLENGE]);

        $passkey = UserPasskey::create(
            $userId,
            base64_encode($data->credentialId),
            $data->credentialPublicKey,
            $name !== '' ? $name : _('Passkey'),
            (int) ($data->signatureCounter ?? 0),
            isset($payload['transports']) ? json_encode($payload['transports']) : null,
            $data->AAGUID ? (string) $data->AAGUID : null
        );

        return $this->passkeyRepository->save($passkey);
    }

    public function getVerifyOptions(int $userId): object
    {
        $webAuthn = $this->createWebAuthn();
        $credentialIds = array_map(
            fn (UserPasskey $passkey): string => $passkey->getCredentialIdBinary(),
            $this->passkeyRepository->findByUserId($userId)
        );
        if ($credentialIds === []) {
            throw new \RuntimeException(_('No passkey is registered for this account.'));
        }

        $options = $webAuthn->getGetArgs($credentialIds, 120, true, true, true, true, true, 'preferred');
        $_SESSION[self::VERIFY_CHALLENGE] = base64_encode($webAuthn->getChallenge()->getBinaryString());

        return $options;
    }

    public function verify(int $userId, array $payload): UserPasskey
    {
        $challenge = base64_decode($_SESSION[self::VERIFY_CHALLENGE] ?? '', true);
        if ($challenge === false || $challenge === '') {
            throw new \RuntimeException(_('Passkey verification expired. Please try again.'));
        }

        $credentialId = base64_encode(base64_decode((string) ($payload['id'] ?? ''), true) ?: '');
        $passkey = $this->passkeyRepository->findByCredentialId($credentialId);
        if (!$passkey || $passkey->getUserId() !== $userId) {
            throw new \RuntimeException(_('Passkey is not registered for this account.'));
        }

        $webAuthn = $this->createWebAuthn();
        $webAuthn->processGet(
            base64_decode((string) ($payload['clientDataJSON'] ?? ''), true) ?: '',
            base64_decode((string) ($payload['authenticatorData'] ?? ''), true) ?: '',
            base64_decode((string) ($payload['signature'] ?? ''), true) ?: '',
            $passkey->getPublicKey(),
            $challenge,
            $passkey->getSignCount(),
            false,
            true
        );

        unset($_SESSION[self::VERIFY_CHALLENGE]);

        $passkey->markUsed($webAuthn->getSignatureCounter());
        return $this->passkeyRepository->save($passkey);
    }

    /**
     * @return array<UserPasskey>
     */
    public function getUserPasskeys(int $userId): array
    {
        return $this->passkeyRepository->findByUserId($userId);
    }

    public function deleteUserPasskey(int $id, int $userId): bool
    {
        return $this->passkeyRepository->deleteByIdForUser($id, $userId);
    }

    private function createWebAuthn(): WebAuthn
    {
        return new WebAuthn($this->config->get('interface', 'title', 'Poweradmin') ?: 'Poweradmin', $this->getRpId(), ['none']);
    }

    private function getRpId(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        return preg_replace('/:\d+$/', '', $host) ?: 'localhost';
    }
}
