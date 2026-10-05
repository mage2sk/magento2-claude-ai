<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model;

use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\App\CacheInterface;
use Magento\User\Model\User;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\RateLimiter;
use PHPUnit\Framework\TestCase;

class RateLimiterTest extends TestCase
{
    private function session(?int $userId, bool $throws = false): AdminSession
    {
        $user = null;
        if ($userId !== null) {
            $user = $this->createStub(User::class);
            $user->method('getId')->willReturn($userId);
        }
        $session = $this->createStub(AdminSession::class);
        $session->method('__call')->willReturnCallback(
            function (string $method) use ($user, $throws) {
                if ($throws) {
                    throw new \RuntimeException('no session');
                }
                return $method === 'getUser' ? $user : null;
            }
        );
        return $session;
    }

    private function config(int $limit): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('getAdminRateLimit')->willReturn($limit);
        return $config;
    }

    public function testHitBelowLimitIncrementsCounter(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('2');
        $cache->expects($this->once())
            ->method('save')
            ->with('3', $this->stringStartsWith('panth_claudeai_rate_9_'), [], 3600);

        $limiter = new RateLimiter($cache, $this->session(9), $this->config(5));
        $this->assertTrue($limiter->hit());
    }

    public function testHitAtLimitIsRejectedWithoutSaving(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn('5');
        $cache->expects($this->never())->method('save');

        $limiter = new RateLimiter($cache, $this->session(9), $this->config(5));
        $this->assertFalse($limiter->hit());
    }

    public function testKeyIsPerUserAndPerHour(): void
    {
        $keys = [];
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(function (string $key) use (&$keys) {
            $keys[] = $key;
            return false;
        });

        (new RateLimiter($cache, $this->session(3), $this->config(10)))->hit();
        (new RateLimiter($cache, $this->session(4), $this->config(10)))->hit();

        $this->assertSame('panth_claudeai_rate_3_' . gmdate('YmdH'), $keys[0]);
        $this->assertSame('panth_claudeai_rate_4_' . gmdate('YmdH'), $keys[1]);
    }

    public function testMissingOrBrokenSessionFallsBackToUserZero(): void
    {
        $keys = [];
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(function (string $key) use (&$keys) {
            $keys[] = $key;
            return '';
        });

        $this->assertTrue((new RateLimiter($cache, $this->session(null), $this->config(1)))->hit());
        $this->assertTrue((new RateLimiter($cache, $this->session(1, true), $this->config(1)))->hit());

        $this->assertStringStartsWith('panth_claudeai_rate_0_', $keys[0]);
        $this->assertStringStartsWith('panth_claudeai_rate_0_', $keys[1]);
    }
}
