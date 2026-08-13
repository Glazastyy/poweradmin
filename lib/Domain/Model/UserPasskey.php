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

namespace Poweradmin\Domain\Model;

use DateTime;

class UserPasskey
{
    public function __construct(
        private readonly int $id,
        private readonly int $userId,
        private string $credentialId,
        private string $publicKey,
        private string $name,
        private int $signCount,
        private ?string $transports,
        private ?string $aaguid,
        private readonly DateTime $createdAt,
        private ?DateTime $lastUsedAt,
    ) {
    }

    public static function create(
        int $userId,
        string $credentialId,
        string $publicKey,
        string $name,
        int $signCount,
        ?string $transports,
        ?string $aaguid
    ): self {
        return new self(
            0,
            $userId,
            $credentialId,
            $publicKey,
            $name,
            $signCount,
            $transports,
            $aaguid,
            new DateTime(),
            null
        );
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getCredentialId(): string
    {
        return $this->credentialId;
    }

    public function getCredentialIdBinary(): string
    {
        return base64_decode($this->credentialId, true) ?: '';
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSignCount(): int
    {
        return $this->signCount;
    }

    public function setSignCount(int $signCount): void
    {
        $this->signCount = $signCount;
    }

    public function getTransports(): ?string
    {
        return $this->transports;
    }

    public function getAaguid(): ?string
    {
        return $this->aaguid;
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?DateTime
    {
        return $this->lastUsedAt;
    }

    public function markUsed(?int $signCount): void
    {
        if ($signCount !== null) {
            $this->signCount = $signCount;
        }
        $this->lastUsedAt = new DateTime();
    }
}
