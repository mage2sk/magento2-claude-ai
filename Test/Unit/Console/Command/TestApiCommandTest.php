<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Console\Command;

use Magento\Framework\Exception\LocalizedException;
use Panth\ClaudeAi\Console\Command\TestApiCommand;
use Panth\ClaudeAi\Model\ClaudeClient;
use Panth\ClaudeAi\Model\Config;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestApiCommandTest extends TestCase
{
    private function config(string $key): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('getApiKey')->willReturn($key);
        return $config;
    }

    public function testMissingKeyFailsWithoutCallingClient(): void
    {
        $client = $this->createMock(ClaudeClient::class);
        $client->expects($this->never())->method('send');

        $command = new TestApiCommand($this->config(''), $client);
        $tester = new CommandTester($command);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertSame('panth_claudeai:test-api', $command->getName());
        $this->assertStringContainsString('API key not configured.', $tester->getDisplay());
    }

    public function testSuccessfulRoundtripPrintsReplyAndUsage(): void
    {
        $client = $this->createMock(ClaudeClient::class);
        $client->expects($this->once())->method('send')
            ->with([['role' => 'user', 'content' => 'Reply with the single word OK.']], $this->stringContains('connectivity test'), [])
            ->willReturn([
                'content' => [
                    ['type' => 'thinking', 'thinking' => 'hmm'],
                    ['type' => 'text', 'text' => ' OK'],
                    ['type' => 'text', 'text' => '! '],
                ],
                'usage' => ['input_tokens' => 21, 'output_tokens' => 3],
            ]);

        $tester = new CommandTester(new TestApiCommand($this->config('key'), $client));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $out = $tester->getDisplay();
        $this->assertStringContainsString('Reply:        "OK!"', $out);
        $this->assertStringContainsString('Input tokens: 21', $out);
        $this->assertStringContainsString('Output tokens:3', $out);
    }

    public function testClientErrorFails(): void
    {
        $client = $this->createStub(ClaudeClient::class);
        $client->method('send')->willThrowException(new LocalizedException(__('API error (authentication_error): invalid x-api-key')));

        $tester = new CommandTester(new TestApiCommand($this->config('bad'), $client));

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('invalid x-api-key', $tester->getDisplay());
    }
}
