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

namespace Poweradmin\Infrastructure\Repository;

use DateTime;
use PDO;
use PDOException;
use Poweradmin\Domain\Model\UserPasskey;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\DbCompat;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class DbUserPasskeyRepository
{
    private static bool $schemaChecked = false;
    private PDO $db;
    private ConfigurationManager $config;
    private LoggerInterface $logger;

    public function __construct(PDO $db, ConfigurationManager $config, ?LoggerInterface $logger = null)
    {
        $this->db = $db;
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
        $this->ensureSchema();
    }

    /**
     * @return array<UserPasskey>
     */
    public function findByUserId(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, user_id, credential_id, public_key, name, sign_count, transports, aaguid, created_at, last_used_at
            FROM user_passkeys
            WHERE user_id = :user_id
            ORDER BY created_at ASC
        ");
        $stmt->execute(['user_id' => $userId]);

        return array_map(fn (array $row): UserPasskey => $this->hydrate($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findByCredentialId(string $credentialId): ?UserPasskey
    {
        $stmt = $this->db->prepare("
            SELECT id, user_id, credential_id, public_key, name, sign_count, transports, aaguid, created_at, last_used_at
            FROM user_passkeys
            WHERE credential_id = :credential_id
        ");
        $stmt->execute(['credential_id' => $credentialId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrate($row) : null;
    }

    public function save(UserPasskey $passkey): UserPasskey
    {
        if ($passkey->getId() === 0) {
            $stmt = $this->db->prepare("
                INSERT INTO user_passkeys (
                    user_id, credential_id, public_key, name, sign_count, transports, aaguid, created_at, last_used_at
                ) VALUES (
                    :user_id, :credential_id, :public_key, :name, :sign_count, :transports, :aaguid, :created_at, :last_used_at
                )
            ");
            $stmt->execute([
                'user_id' => $passkey->getUserId(),
                'credential_id' => $passkey->getCredentialId(),
                'public_key' => $passkey->getPublicKey(),
                'name' => $passkey->getName(),
                'sign_count' => $passkey->getSignCount(),
                'transports' => $passkey->getTransports(),
                'aaguid' => $passkey->getAaguid(),
                'created_at' => $passkey->getCreatedAt()->format('Y-m-d H:i:s'),
                'last_used_at' => $passkey->getLastUsedAt()?->format('Y-m-d H:i:s'),
            ]);

            return $this->findByCredentialId($passkey->getCredentialId()) ?? $passkey;
        }

        $stmt = $this->db->prepare("
            UPDATE user_passkeys
            SET sign_count = :sign_count, last_used_at = :last_used_at
            WHERE id = :id
        ");
        $stmt->execute([
            'id' => $passkey->getId(),
            'sign_count' => $passkey->getSignCount(),
            'last_used_at' => $passkey->getLastUsedAt()?->format('Y-m-d H:i:s'),
        ]);

        return $passkey;
    }

    public function deleteByIdForUser(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM user_passkeys WHERE id = :id AND user_id = :user_id");
        return $stmt->execute(['id' => $id, 'user_id' => $userId]);
    }

    private function hydrate(array $row): UserPasskey
    {
        return new UserPasskey(
            (int) $row['id'],
            (int) $row['user_id'],
            (string) $row['credential_id'],
            (string) $row['public_key'],
            (string) $row['name'],
            (int) $row['sign_count'],
            $row['transports'] ?? null,
            $row['aaguid'] ?? null,
            new DateTime($row['created_at']),
            !empty($row['last_used_at']) ? new DateTime($row['last_used_at']) : null
        );
    }

    private function ensureSchema(): void
    {
        if (self::$schemaChecked) {
            return;
        }

        self::$schemaChecked = true;
        $dbType = $this->config->get('database', 'type', 'mysql');
        $now = DbCompat::now($dbType);

        try {
            if ($dbType === 'pgsql') {
                $this->db->exec("CREATE SEQUENCE IF NOT EXISTS user_passkeys_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1");
                $this->db->exec("
                    CREATE TABLE IF NOT EXISTS user_passkeys (
                        id integer DEFAULT nextval('user_passkeys_id_seq') NOT NULL,
                        user_id integer NOT NULL,
                        credential_id text NOT NULL,
                        public_key text NOT NULL,
                        name character varying(255) NOT NULL,
                        sign_count bigint DEFAULT 0 NOT NULL,
                        transports text DEFAULT NULL,
                        aaguid character varying(64) DEFAULT NULL,
                        created_at timestamp DEFAULT $now NOT NULL,
                        last_used_at timestamp DEFAULT NULL,
                        CONSTRAINT user_passkeys_pkey PRIMARY KEY (id)
                    )
                ");
            } elseif ($dbType === 'sqlite') {
                $this->db->exec("
                    CREATE TABLE IF NOT EXISTS user_passkeys (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        user_id INTEGER NOT NULL,
                        credential_id TEXT NOT NULL,
                        public_key TEXT NOT NULL,
                        name TEXT NOT NULL,
                        sign_count INTEGER NOT NULL DEFAULT 0,
                        transports TEXT DEFAULT NULL,
                        aaguid TEXT DEFAULT NULL,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
                        last_used_at DATETIME DEFAULT NULL
                    )
                ");
            } else {
                $this->db->exec("
                    CREATE TABLE IF NOT EXISTS user_passkeys (
                        id int(11) NOT NULL AUTO_INCREMENT,
                        user_id int(11) NOT NULL,
                        credential_id text NOT NULL,
                        public_key text NOT NULL,
                        name varchar(255) NOT NULL,
                        sign_count bigint NOT NULL DEFAULT 0,
                        transports text DEFAULT NULL,
                        aaguid varchar(64) DEFAULT NULL,
                        created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        last_used_at timestamp NULL DEFAULT NULL,
                        PRIMARY KEY (id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
            }

            if ($dbType === 'mysql' || $dbType === 'mysqli') {
                $this->createMysqlIndexIfMissing('idx_user_passkeys_credential_id', 'CREATE UNIQUE INDEX idx_user_passkeys_credential_id ON user_passkeys (credential_id(255))');
                $this->createMysqlIndexIfMissing('idx_user_passkeys_user_id', 'CREATE INDEX idx_user_passkeys_user_id ON user_passkeys (user_id)');
            } else {
                $this->db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_user_passkeys_credential_id ON user_passkeys (credential_id)");
                $this->db->exec("CREATE INDEX IF NOT EXISTS idx_user_passkeys_user_id ON user_passkeys (user_id)");
            }
        } catch (PDOException $e) {
            $this->logger->error('DbUserPasskeyRepository::ensureSchema failed: {error}', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function createMysqlIndexIfMissing(string $indexName, string $sql): void
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'user_passkeys'
              AND index_name = :index_name
        ");
        $stmt->execute(['index_name' => $indexName]);

        if ((int) $stmt->fetchColumn() === 0) {
            $this->db->exec($sql);
        }
    }
}
