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

namespace Poweradmin\Application\Service;

use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

/**
 * Short-lived cache for expensive UI read models.
 *
 * This cache intentionally stores derived data for list/search screens, not
 * rendered HTML. A namespace file lets write paths invalidate all entries
 * immediately, and clear() removes old cache files to avoid unbounded growth.
 */
class UiDataCacheService
{
    private const NAMESPACE_FILE = 'namespace';

    private bool $enabled;
    private int $ttl;
    private string $directory;

    public function __construct(ConfigurationInterface $config, ?string $directory = null)
    {
        $this->enabled = (bool)$config->get('interface', 'cache_enabled', true);
        $this->ttl = max(0, (int)$config->get('interface', 'cache_ttl', 60));
        $this->directory = $this->normalizeDirectory($directory ?? (string)$config->get('interface', 'cache_dir', $this->defaultDirectory()));
    }

    /**
     * @template T
     * @param callable():T $producer
     * @return T
     */
    public function remember(string $scope, array $parts, callable $producer): mixed
    {
        if (!$this->enabled || $this->ttl <= 0 || !$this->ensureDirectory()) {
            return $producer();
        }

        $file = $this->cacheFile($scope, $parts);
        $cached = $this->read($file);
        if ($cached !== null) {
            return $cached;
        }

        $value = $producer();
        $this->write($file, $value);
        return $value;
    }

    public function clear(): void
    {
        if (!$this->ensureDirectory()) {
            return;
        }

        @file_put_contents($this->namespacePath(), str_replace('.', '', uniqid('', true)), LOCK_EX);
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.cache') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function read(string $file): mixed
    {
        if (!is_file($file)) {
            return null;
        }

        $payload = @file_get_contents($file);
        if ($payload === false) {
            return null;
        }

        $entry = @unserialize($payload, ['allowed_classes' => false]);
        if (!is_array($entry) || !array_key_exists('expires_at', $entry) || !array_key_exists('value', $entry)) {
            return null;
        }

        if ((int)$entry['expires_at'] < time()) {
            @unlink($file);
            return null;
        }

        return $entry['value'];
    }

    private function write(string $file, mixed $value): void
    {
        $entry = [
            'expires_at' => time() + $this->ttl,
            'value' => $value,
        ];

        @file_put_contents($file, serialize($entry), LOCK_EX);
    }

    private function cacheFile(string $scope, array $parts): string
    {
        $namespace = $this->namespace();
        $userId = $_SESSION['userid'] ?? 'anonymous';
        $key = hash('sha256', serialize([dirname(__DIR__, 3), $namespace, $userId, $scope, $parts]));
        return $this->directory . DIRECTORY_SEPARATOR . $scope . '-' . $key . '.cache';
    }

    private function namespace(): string
    {
        $path = $this->namespacePath();
        if (!is_file($path)) {
            @file_put_contents($path, '1', LOCK_EX);
        }

        $namespace = @file_get_contents($path);
        return is_string($namespace) && $namespace !== '' ? trim($namespace) : '1';
    }

    private function namespacePath(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . self::NAMESPACE_FILE;
    }

    private function ensureDirectory(): bool
    {
        if (is_dir($this->directory)) {
            return is_writable($this->directory);
        }

        return @mkdir($this->directory, 0775, true) || is_dir($this->directory);
    }

    private function defaultDirectory(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'poweradmin-ui-cache';
    }

    private function normalizeDirectory(string $directory): string
    {
        if ($directory === '') {
            return $this->defaultDirectory();
        }

        if ($directory[0] === DIRECTORY_SEPARATOR || preg_match('/^[A-Za-z]:[\\\\\\/]/', $directory) === 1) {
            return $directory;
        }

        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . $directory;
    }
}
