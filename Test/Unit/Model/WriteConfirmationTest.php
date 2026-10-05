<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model;

use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\App\CacheInterface;
use Magento\User\Model\User;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\WriteConfirmation;
use PHPUnit\Framework\TestCase;

class WriteConfirmationTest extends TestCase
{
    private array $store = [];

    private function cache(): CacheInterface
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn(string $key) => $this->store[$key] ?? false);
        $cache->method('save')->willReturnCallback(function (string $data, string $key) {
            $this->store[$key] = $data;
            return true;
        });
        $cache->method('remove')->willReturnCallback(function (string $key) {
            unset($this->store[$key]);
            return true;
        });
        return $cache;
    }

    private function session(int $userId = 5): AdminSession
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($userId);
        $session = $this->createStub(AdminSession::class);
        $session->method('__call')->willReturnCallback(fn(string $m) => $m === 'getUser' ? $user : null);
        return $session;
    }

    private function config(bool $required = true, bool $dryRun = false, int $threshold = 2): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('isConfirmationRequired')->willReturn($required);
        $config->method('isDryRun')->willReturn($dryRun);
        $config->method('getConfirmationThreshold')->willReturn($threshold);
        return $config;
    }

    public function testNotRequiredReturnsNull(): void
    {
        $wc = new WriteConfirmation($this->cache(), $this->session(), $this->config(false));
        $this->assertNull($wc->check('tool', ['a', 'b', 'c', 'd'], []));
    }

    public function testDryRunReturnsNull(): void
    {
        $wc = new WriteConfirmation($this->cache(), $this->session(), $this->config(true, true));
        $this->assertNull($wc->check('tool', ['a', 'b', 'c', 'd'], []));
    }

    public function testAtOrBelowThresholdReturnsNull(): void
    {
        $wc = new WriteConfirmation($this->cache(), $this->session(), $this->config(true, false, 2));
        $this->assertNull($wc->check('tool', ['a', 'b'], []));
        $this->assertSame([], $this->store);
    }

    public function testAboveThresholdIssuesCode(): void
    {
        $wc = new WriteConfirmation($this->cache(), $this->session(), $this->config());
        $result = $wc->check('update_product_price', ['C', 'A', 'B', 'A', 'D', 'E', 'F'], ['new_price' => 9.0]);

        $this->assertSame('needs_confirmation', $result['status']);
        $this->assertSame(0, $result['affected_count']);
        $this->assertSame(6, $result['matched']);
        $this->assertSame(['A', 'B', 'C', 'D', 'E'], $result['sample_skus']);
        $this->assertMatchesRegularExpression('/^[0-9A-F]{6}$/', $result['confirmation_code']);
        $this->assertStringContainsString('CONFIRM ' . $result['confirmation_code'], $result['message']);
        $this->assertStringContainsString('affects 6 products', $result['message']);
        $this->assertCount(1, $this->store);
        $this->assertStringStartsWith('panth_claudeai_confirm_5_', array_key_first($this->store));
    }

    public function testSameRequestReusesCodeUntilConfirmed(): void
    {
        $wc = new WriteConfirmation($this->cache(), $this->session(), $this->config());
        $first = $wc->check('t', ['a', 'b', 'c'], ['x' => 1, 'y' => 2]);
        $second = $wc->check('t', ['c', 'b', 'a'], ['y' => 2, 'x' => 1]);

        $this->assertSame($first['confirmation_code'], $second['confirmation_code']);
    }

    public function testMatchingCodeInUserMessageApprovesOnce(): void
    {
        $wc = new WriteConfirmation($this->cache(), $this->session(), $this->config());
        $code = $wc->check('t', ['a', 'b', 'c'], [])['confirmation_code'];

        $wc->setUserMessage('yes please, confirm ' . strtolower($code));
        $this->assertNull($wc->check('t', ['a', 'b', 'c'], []));
        $this->assertSame([], $this->store);

        $again = $wc->check('t', ['a', 'b', 'c'], []);
        $this->assertSame('needs_confirmation', $again['status']);
    }

    public function testCodeForDifferentArgumentsDoesNotApprove(): void
    {
        $wc = new WriteConfirmation($this->cache(), $this->session(), $this->config());
        $code = $wc->check('t', ['a', 'b', 'c'], ['price' => 1])['confirmation_code'];

        $wc->setUserMessage('CONFIRM ' . $code);
        $result = $wc->check('t', ['a', 'b', 'c'], ['price' => 2]);

        $this->assertSame('needs_confirmation', $result['status']);
    }

    public function testCodeEmbeddedInLongerTokenDoesNotApprove(): void
    {
        $wc = new WriteConfirmation($this->cache(), $this->session(), $this->config());
        $code = $wc->check('t', ['a', 'b', 'c'], [])['confirmation_code'];

        $wc->setUserMessage('X' . $code . 'X');
        $this->assertSame('needs_confirmation', $wc->check('t', ['a', 'b', 'c'], [])['status']);
    }

    public function testCodesAreScopedPerAdminUser(): void
    {
        $cache = $this->cache();
        $first = new WriteConfirmation($cache, $this->session(1), $this->config());
        $code = $first->check('t', ['a', 'b', 'c'], [])['confirmation_code'];

        $other = new WriteConfirmation($cache, $this->session(2), $this->config());
        $other->setUserMessage('CONFIRM ' . $code);

        $this->assertSame('needs_confirmation', $other->check('t', ['a', 'b', 'c'], [])['status']);
        $this->assertCount(2, $this->store);
    }
}
