<?php

namespace Poweradmin\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\UiDataCacheService;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

class UiDataCacheServiceTest extends TestCase
{
    public function testRememberCachesUntilCleared(): void
    {
        $cacheDir = sys_get_temp_dir() . '/poweradmin-ui-cache-service-test-' . uniqid('', true);
        $cache = new UiDataCacheService($this->config(true, 60, $cacheDir));
        $calls = 0;

        $first = $cache->remember('scope', ['a' => 1], function () use (&$calls) {
            $calls++;
            return ['value' => $calls];
        });
        $second = $cache->remember('scope', ['a' => 1], function () use (&$calls) {
            $calls++;
            return ['value' => $calls];
        });

        $this->assertSame(['value' => 1], $first);
        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);

        $cache->clear();
        $third = $cache->remember('scope', ['a' => 1], function () use (&$calls) {
            $calls++;
            return ['value' => $calls];
        });

        $this->assertSame(['value' => 2], $third);
        $this->assertSame(2, $calls);
    }

    public function testTtlZeroDisablesCache(): void
    {
        $cacheDir = sys_get_temp_dir() . '/poweradmin-ui-cache-service-test-' . uniqid('', true);
        $cache = new UiDataCacheService($this->config(true, 0, $cacheDir));
        $calls = 0;

        $cache->remember('scope', [], function () use (&$calls) {
            return ++$calls;
        });
        $cache->remember('scope', [], function () use (&$calls) {
            return ++$calls;
        });

        $this->assertSame(2, $calls);
    }

    private function config(bool $enabled, int $ttl, string $cacheDir): ConfigurationInterface
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(function (string $section, string $key, $default = null) use ($enabled, $ttl, $cacheDir) {
            if ($section === 'interface' && $key === 'cache_enabled') {
                return $enabled;
            }
            if ($section === 'interface' && $key === 'cache_ttl') {
                return $ttl;
            }
            if ($section === 'interface' && $key === 'cache_dir') {
                return $cacheDir;
            }
            return $default;
        });

        return $config;
    }
}
