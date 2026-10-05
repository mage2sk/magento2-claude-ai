<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Logger;

use Magento\Framework\Filesystem\DriverInterface;
use Monolog\Level;
use Monolog\LogRecord;
use Panth\ClaudeAi\Logger\Handler;
use Panth\ClaudeAi\Model\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HandlerTest extends TestCase
{
    private function record(Level $level): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'panth', $level, 'message');
    }

    private function handler(?bool $fileLogEnabled, bool $fallback): Handler
    {
        $config = $this->createStub(Config::class);
        if ($fileLogEnabled === null) {
            $config->method('isFileLogEnabled')->willThrowException(new \RuntimeException('config not ready'));
        } else {
            $config->method('isFileLogEnabled')->willReturn($fileLogEnabled);
        }
        return new Handler(
            $this->createStub(DriverInterface::class),
            $config,
            sys_get_temp_dir(),
            null,
            $fallback
        );
    }

    #[DataProvider('routingProvider')]
    public function testRoutingBetweenDedicatedAndFallbackHandler(?bool $enabled, bool $fallback, bool $expected): void
    {
        $this->assertSame($expected, $this->handler($enabled, $fallback)->isHandling($this->record(Level::Error)));
    }

    public static function routingProvider(): array
    {
        return [
            'file log on, dedicated handler' => [true, false, true],
            'file log on, fallback handler' => [true, true, false],
            'file log off, dedicated handler' => [false, false, false],
            'file log off, fallback handler' => [false, true, true],
            'config failure uses fallback' => [null, true, true],
            'config failure skips dedicated' => [null, false, false],
        ];
    }

    public function testRecordsBelowInfoAreIgnored(): void
    {
        $this->assertFalse($this->handler(true, false)->isHandling($this->record(Level::Debug)));
        $this->assertTrue($this->handler(true, false)->isHandling($this->record(Level::Info)));
    }
}
