<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model;

use Panth\ClaudeAi\Model\Activity\Logger as ActivityLogger;
use Panth\ClaudeAi\Model\ClaudeClient;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\MessageStore;
use Panth\ClaudeAi\Model\Orchestrator;
use Panth\ClaudeAi\Model\Tool\ToolInterface;
use Panth\ClaudeAi\Model\ToolRegistry;
use Panth\ClaudeAi\Model\TrainingRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrchestratorEdgeCasesTest extends TestCase
{
    private array $responses = [];
    private array $sentMessages = [];
    private ?string $systemPrompt = null;
    private array $activity = [];
    private array $stored = [];

    private function orchestrator(
        array $tools = [],
        int $maxIterations = 5,
        bool $dryRun = false,
        bool $confirm = true,
        ?LoggerInterface $logger = null
    ): Orchestrator {
        $config = $this->createStub(Config::class);
        $config->method('getMaxIterations')->willReturn($maxIterations);
        $config->method('getMaxBulkUpdate')->willReturn(250);
        $config->method('isDryRun')->willReturn($dryRun);
        $config->method('isConfirmationRequired')->willReturn($confirm);
        $config->method('getConfirmationThreshold')->willReturn(7);

        $client = $this->createStub(ClaudeClient::class);
        $client->method('send')->willReturnCallback(function (array $messages, string $system) {
            $this->sentMessages[] = $messages;
            $this->systemPrompt = $system;
            return array_shift($this->responses) ?? ['content' => [], 'stop_reason' => 'end_turn'];
        });

        $activity = $this->createStub(ActivityLogger::class);
        $activity->method('log')->willReturnCallback(function (array $row) {
            $this->activity[] = $row;
        });

        $store = $this->createStub(MessageStore::class);
        $store->method('record')->willReturnCallback(function ($cid, $seq, $role, $content, $surface = 'admin', $usage = []) {
            $this->stored[] = ['seq' => $seq, 'role' => $role, 'usage' => $usage, 'surface' => $surface];
        });

        $training = $this->createStub(TrainingRepository::class);
        $training->method('renderForSystemPrompt')->willReturn("\n# Training examples (merchant-curated)\n");

        return new Orchestrator(
            $config,
            $client,
            new ToolRegistry($tools),
            $activity,
            $logger ?? $this->createStub(LoggerInterface::class),
            $training,
            $store,
            'cli'
        );
    }

    private function tool(string $name, callable $execute): ToolInterface
    {
        $tool = $this->createStub(ToolInterface::class);
        $tool->method('name')->willReturn($name);
        $tool->method('definition')->willReturn(['name' => $name]);
        $tool->method('execute')->willReturnCallback($execute);
        return $tool;
    }

    private static function toolUse(string $id, string $name, array $input = []): array
    {
        return ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input];
    }

    public function testToolLoopRunsToolsAndFeedsResultsBack(): void
    {
        $this->responses = [
            [
                'content' => [['type' => 'text', 'text' => 'Checking. '], self::toolUse('t1', 'orders', ['action' => 'search'])],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 100, 'output_tokens' => 10, 'cache_read_input_tokens' => 5, 'cache_creation_input_tokens' => 2],
            ],
            [
                'content' => [['type' => 'text', 'text' => 'You have 3 orders.']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 200, 'output_tokens' => 20],
            ],
        ];
        $orders = $this->tool('orders', fn(array $in) => ['status' => 'success', 'affected_count' => 3, 'summary' => '3 orders', 'in' => $in]);

        $events = [];
        $result = $this->orchestrator([$orders])->run([], 'How many orders?', 'cid1', function ($event, $data) use (&$events) {
            $events[] = $event;
        });

        $this->assertSame('You have 3 orders.', $result['text']);
        $this->assertSame(2, $result['iterations']);
        $this->assertSame(['input' => 300, 'output' => 30, 'cache_read' => 5, 'cache_write' => 2], $result['usage']);
        $this->assertSame('orders', $result['tool_calls'][0]['name']);
        $this->assertSame(['action' => 'search'], $result['tool_calls'][0]['output']['in']);
        $this->assertSame(['thinking', 'tool_start', 'tool_done', 'thinking', 'writing_reply'], $events);

        $toolResultMessage = $this->sentMessages[1][2];
        $this->assertSame('user', $toolResultMessage['role']);
        $this->assertSame('t1', $toolResultMessage['content'][0]['tool_use_id']);
        $this->assertFalse($toolResultMessage['content'][0]['is_error']);

        $this->assertSame(['user', 'assistant', 'tool_result', 'assistant'], array_column($this->stored, 'role'));
        $this->assertSame([0, 1, 2, 3], array_column($this->stored, 'seq'));
        $this->assertSame('cli', $this->stored[0]['surface']);
        $this->assertSame(['input_tokens' => 100, 'output_tokens' => 10, 'cache_read_tokens' => 5, 'cache_write_tokens' => 2], $this->stored[1]['usage']);

        $this->assertSame(['user', 'tool', 'assistant'], array_column($this->activity, 'actor_type'));
        $this->assertSame(3, $this->activity[1]['affected_count']);
        $this->assertSame('You have 3 orders.', $this->activity[2]['result']);
        $this->assertSame(300, $this->activity[2]['input_tokens']);
    }

    public function testUnknownToolAndToolErrorsAreReportedAsErrors(): void
    {
        $this->responses = [
            [
                'content' => [
                    self::toolUse('t1', 'drop_database'),
                    self::toolUse('t2', 'broken'),
                    self::toolUse('t3', 'refusing'),
                ],
                'stop_reason' => 'tool_use',
            ],
            ['content' => [['type' => 'text', 'text' => 'Sorry.']], 'stop_reason' => 'end_turn'],
        ];
        $broken = $this->tool('broken', function () {
            throw new \RuntimeException('kaboom');
        });
        $refusing = $this->tool('refusing', fn() => ['status' => 'error', 'message' => 'nope']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('[panth_claudeai] tool exception: kaboom', ['tool' => 'broken']);

        $result = $this->orchestrator([$broken, $refusing], 5, false, true, $logger)->run([], 'go', 'cid1');

        $outputs = array_column($result['tool_calls'], 'output');
        $this->assertSame(['status' => 'error', 'message' => 'Unknown tool: drop_database'], $outputs[0]);
        $this->assertSame(['status' => 'error', 'message' => 'kaboom'], $outputs[1]);
        $this->assertSame(['status' => 'error', 'message' => 'nope'], $outputs[2]);

        $results = $this->sentMessages[1][2]['content'];
        $this->assertSame([true, true, true], array_column($results, 'is_error'));
        $this->assertSame(['error', 'error', 'error'], array_column(array_slice($this->activity, 1, 3), 'status'));
    }

    public function testIterationCapStopsEndlessToolUse(): void
    {
        $loop = ['content' => [self::toolUse('t', 'ping')], 'stop_reason' => 'tool_use'];
        $this->responses = [$loop, $loop, $loop, $loop];
        $ping = $this->tool('ping', fn() => ['status' => 'success']);

        $result = $this->orchestrator([$ping], 2)->run([], 'loop', 'cid1');

        $this->assertCount(2, $this->sentMessages);
        $this->assertSame(3, $result['iterations']);
        $this->assertSame('(Claude returned no text)', $result['text']);
        $this->assertCount(2, $result['tool_calls']);
    }

    public function testProgressCallbackFailuresAreIgnored(): void
    {
        $this->responses = [['content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn']];

        $result = $this->orchestrator()->run([], 'hi', 'cid1', function () {
            throw new \RuntimeException('client went away');
        });

        $this->assertSame('ok', $result['text']);
    }

    public function testStructuredUserContentIsLoggedAsJson(): void
    {
        $content = [['type' => 'text', 'text' => 'see / this']];
        $this->orchestrator()->run([], $content, 'cid1');

        $this->assertSame('[{"type":"text","text":"see / this"}]', $this->activity[0]['prompt']);
        $this->assertSame($content, $this->sentMessages[0][0]['content']);
    }

    public function testSystemPromptReflectsStorePolicy(): void
    {
        $this->orchestrator([], 1, true, true)->run([], 'hi', 'cid1');

        $this->assertStringContainsString('Single-call write cap: 250 items.', $this->systemPrompt);
        $this->assertStringContainsString('Dry run mode is ON.', $this->systemPrompt);
        $this->assertStringContainsString('affect MORE THAN 7 items', $this->systemPrompt);
        $this->assertStringContainsString('# Training examples (merchant-curated)', $this->systemPrompt);
        $this->assertStringContainsString('# Refusals (always)', $this->systemPrompt);
    }

    public function testSystemPromptWithoutConfirmationFlow(): void
    {
        $this->orchestrator([], 1, false, false)->run([], 'hi', 'cid1');

        $this->assertStringContainsString('Dry run mode is OFF.', $this->systemPrompt);
        $this->assertStringNotContainsString('# Confirmation flow', $this->systemPrompt);
    }
}
