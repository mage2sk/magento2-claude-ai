<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model;

use Panth\ClaudeAi\Model\Activity\Logger as ActivityLogger;
use Panth\ClaudeAi\Model\ClaudeClient;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\MessageStore;
use Panth\ClaudeAi\Model\Orchestrator;
use Panth\ClaudeAi\Model\ToolRegistry;
use Panth\ClaudeAi\Model\TrainingRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class OrchestratorTest extends TestCase
{
    public function testOnlyGivenHistoryIsSentAndSequenceContinues(): void
    {
        $history = [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hello']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'hi']]],
        ];

        $config = $this->createStub(Config::class);
        $config->method('getMaxIterations')->willReturn(2);
        $config->method('getMaxBulkUpdate')->willReturn(500);

        $sent = null;
        $client = $this->createStub(ClaudeClient::class);
        $client->method('send')->willReturnCallback(function (array $messages) use (&$sent) {
            $sent = $messages;
            return [
                'content' => [['type' => 'text', 'text' => 'done']],
                'stop_reason' => 'end_turn',
                'usage' => [],
            ];
        });

        $tools = $this->createStub(ToolRegistry::class);
        $tools->method('definitions')->willReturn([]);
        $training = $this->createStub(TrainingRepository::class);
        $training->method('renderForSystemPrompt')->willReturn('');

        $sequences = [];
        $store = $this->createMock(MessageStore::class);
        $store->method('record')->willReturnCallback(function (string $cid, int $sequence) use (&$sequences) {
            $sequences[] = $sequence;
        });

        $orchestrator = new Orchestrator(
            $config,
            $client,
            $tools,
            $this->createStub(ActivityLogger::class),
            $this->createStub(LoggerInterface::class),
            $training,
            $store
        );
        $reply = $orchestrator->run($history, 'next question', 'abc123', null, 6);

        $this->assertSame('done', $reply['text']);
        $this->assertCount(3, $sent);
        $this->assertSame('hello', $sent[0]['content'][0]['text']);
        $this->assertSame('next question', $sent[2]['content']);
        $this->assertSame([6, 7], $sequences);
    }
}
