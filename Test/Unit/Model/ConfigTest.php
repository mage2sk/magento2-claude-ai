<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Panth\ClaudeAi\Model\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private function build(array $values, ?EncryptorInterface $encryptor = null): Config
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(
            function (string $path, string $scopeType) use ($values) {
                $this->assertSame(ScopeInterface::SCOPE_STORE, $scopeType);
                return $values[$path] ?? null;
            }
        );
        return new Config($scope, $encryptor ?? $this->createStub(EncryptorInterface::class));
    }

    public function testApiKeyIsDecrypted(): void
    {
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->expects($this->once())->method('decrypt')->with('0:3:abc')->willReturn('sk-plain');

        $config = $this->build([Config::XML_API_KEY => '0:3:abc'], $encryptor);
        $this->assertSame('sk-plain', $config->getApiKey());
    }

    public function testEmptyApiKeyIsNotDecrypted(): void
    {
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->expects($this->never())->method('decrypt');

        $this->assertSame('', $this->build([], $encryptor)->getApiKey());
    }

    public function testDefaultsWhenNothingConfigured(): void
    {
        $config = $this->build([]);

        $this->assertFalse($config->isEnabled());
        $this->assertSame('claude-opus-4-7', $config->getModel());
        $this->assertSame('high', $config->getEffort());
        $this->assertSame(8192, $config->getMaxTokens());
        $this->assertSame(8, $config->getMaxIterations());
        $this->assertSame(120, $config->getApiTimeout());
        $this->assertFalse($config->isDryRun());
        $this->assertSame(500, $config->getMaxBulkUpdate());
        $this->assertSame(60, $config->getAdminRateLimit());
        $this->assertTrue($config->isConfirmationRequired());
        $this->assertSame(5, $config->getConfirmationThreshold());
        $this->assertTrue($config->isLoggingEnabled());
        $this->assertSame(90, $config->getLogRetentionDays());
        $this->assertSame(30, $config->getCheckpointRetentionDays());
        $this->assertFalse($config->isFileLogEnabled());
    }

    public function testConfiguredValuesAreReturned(): void
    {
        $config = $this->build([
            Config::XML_ENABLED => '1',
            Config::XML_MODEL => 'claude-haiku-4-5',
            Config::XML_EFFORT => 'low',
            Config::XML_MAX_TOKENS => '16000',
            Config::XML_MAX_ITERATIONS => '3',
            Config::XML_API_TIMEOUT => '45',
            Config::XML_DRY_RUN => '1',
            Config::XML_MAX_BULK => '20',
            Config::XML_RATE_ADMIN => '7',
            Config::XML_REQUIRE_CONFIRM => '0',
            Config::XML_CONFIRM_THRESHOLD => '12',
            Config::XML_LOG_ENABLED => '0',
            Config::XML_LOG_RETENTION => '14',
            Config::XML_CHK_RETENTION => '2',
            Config::XML_LOG_FILE => '1',
        ]);

        $this->assertTrue($config->isEnabled());
        $this->assertSame('claude-haiku-4-5', $config->getModel());
        $this->assertSame('low', $config->getEffort());
        $this->assertSame(16000, $config->getMaxTokens());
        $this->assertSame(3, $config->getMaxIterations());
        $this->assertSame(45, $config->getApiTimeout());
        $this->assertTrue($config->isDryRun());
        $this->assertSame(20, $config->getMaxBulkUpdate());
        $this->assertSame(7, $config->getAdminRateLimit());
        $this->assertFalse($config->isConfirmationRequired());
        $this->assertSame(12, $config->getConfirmationThreshold());
        $this->assertFalse($config->isLoggingEnabled());
        $this->assertSame(14, $config->getLogRetentionDays());
        $this->assertSame(2, $config->getCheckpointRetentionDays());
        $this->assertTrue($config->isFileLogEnabled());
    }

    public function testLowerBoundsAreEnforced(): void
    {
        $config = $this->build([
            Config::XML_MAX_TOKENS => '100',
            Config::XML_API_TIMEOUT => '3',
            Config::XML_CONFIRM_THRESHOLD => '-4',
        ]);

        $this->assertSame(1024, $config->getMaxTokens());
        $this->assertSame(10, $config->getApiTimeout());
        $this->assertSame(0, $config->getConfirmationThreshold());
    }

    public function testZeroThresholdIsKept(): void
    {
        $this->assertSame(0, $this->build([Config::XML_CONFIRM_THRESHOLD => '0'])->getConfirmationThreshold());
    }

    #[DataProvider('toolFlagProvider')]
    public function testToolEnabledFlag(?string $stored, bool $expected): void
    {
        $config = $this->build([Config::XML_TOOL_PREFIX . 'orders' => $stored]);
        $this->assertSame($expected, $config->isToolEnabled('orders'));
    }

    public static function toolFlagProvider(): array
    {
        return [
            'unset defaults on' => [null, true],
            'empty defaults on' => ['', true],
            'explicit on' => ['1', true],
            'explicit off' => ['0', false],
        ];
    }
}
