<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Controller\Adminhtml\Chat;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Panth\ClaudeAi\Controller\Adminhtml\Chat\Send;
use Panth\ClaudeAi\Model\AttachmentStorage;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\ConversationHistory;
use Panth\ClaudeAi\Model\Orchestrator;
use Panth\ClaudeAi\Model\Pricing;
use Panth\ClaudeAi\Model\RateLimiter;
use Panth\ClaudeAi\Model\WriteConfirmation;
use Panth\ClaudeAi\Test\Unit\Controller\Adminhtml\ContextBuilderTrait;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SendValidationTest extends TestCase
{
    use ContextBuilderTrait;

    private ?array $data = null;
    private ?int $code = null;
    private $userContent = null;

    private function controller(
        HttpRequest $request,
        bool $enabled = true,
        bool $allowed = true,
        ?AttachmentStorage $storage = null,
        ?WriteConfirmation $confirmation = null
    ): Send {
        $json = $this->createStub(Json::class);
        $json->method('setHttpResponseCode')->willReturnCallback(function ($code) use ($json) {
            $this->code = $code;
            return $json;
        });
        $json->method('setData')->willReturnCallback(function ($data) use ($json) {
            $this->data = $data;
            return $json;
        });
        $jsonFactory = $this->createStub(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('getAdminRateLimit')->willReturn(30);
        $config->method('getModel')->willReturn('claude-haiku-4-5');

        $rateLimiter = $this->createStub(RateLimiter::class);
        $rateLimiter->method('hit')->willReturn($allowed);

        $history = $this->createStub(ConversationHistory::class);
        $history->method('resolve')->willReturn(['conversation_id' => 'new1', 'history' => [], 'next_sequence' => 0]);

        $orchestrator = $this->createStub(Orchestrator::class);
        $orchestrator->method('run')->willReturnCallback(function ($history, $content) {
            $this->userContent = $content;
            return [
                'text' => 'ok',
                'tool_calls' => [],
                'usage' => ['input' => 1_000_000, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0],
                'iterations' => 1,
                'conversation' => [],
            ];
        });

        return new Send(
            $this->buildContext($request, 7, 'secret'),
            $jsonFactory,
            $config,
            $orchestrator,
            new Pricing(),
            $this->createStub(LoggerInterface::class),
            $rateLimiter,
            $confirmation ?? $this->createStub(WriteConfirmation::class),
            $history,
            $storage ?? $this->createStub(AttachmentStorage::class),
            $this->formKeyStub()
        );
    }

    private function jsonRequest(array $body): HttpRequest
    {
        return $this->request([], json_encode($body));
    }

    public function testDisabledModuleIsRejected(): void
    {
        $this->controller($this->jsonRequest(['message' => 'hi']), false)->execute();

        $this->assertSame(400, $this->code);
        $this->assertFalse($this->data['success']);
        $this->assertStringStartsWith('Claude AI is disabled.', $this->data['error']);
    }

    public function testEmptyMessageWithoutAttachmentsIsRejected(): void
    {
        $this->controller($this->jsonRequest(['message' => '   ', 'attachments' => 'bad']))->execute();

        $this->assertSame(400, $this->code);
        $this->assertSame('Message or attachment required.', $this->data['error']);
    }

    public function testRateLimitIsEnforced(): void
    {
        $this->controller($this->jsonRequest(['message' => 'hi']), true, false)->execute();

        $this->assertSame('Admin rate limit reached (30 questions per hour). Please try again later.', $this->data['error']);
    }

    public function testFormParamsAreUsedWhenBodyIsNotJson(): void
    {
        $confirmation = $this->createMock(WriteConfirmation::class);
        $confirmation->expects($this->once())->method('setUserMessage')->with('from form');

        $this->controller($this->request(['message' => ' from form '], 'not-json'), true, true, null, $confirmation)->execute();

        $this->assertTrue($this->data['success']);
        $this->assertSame('from form', $this->userContent);
        $this->assertSame('new1', $this->data['conversation_id']);
        $this->assertSame(1.0, $this->data['cost_usd']);
        $this->assertSame('$1.00', $this->data['cost_display']);
    }

    public function testAttachmentOnlyMessageGetsDefaultPrompt(): void
    {
        $storage = $this->createMock(AttachmentStorage::class);
        $storage->expects($this->once())->method('buildBlocks')->with([['id' => 3]], 7)
            ->willReturn([['type' => 'image', 'source' => []]]);

        $this->controller($this->jsonRequest(['message' => '', 'attachments' => [['id' => 3]]]), true, true, $storage)->execute();

        $this->assertSame([
            ['type' => 'image', 'source' => []],
            ['type' => 'text', 'text' => 'Please look at the attachment(s) above and help me.'],
        ], $this->userContent);
    }

    public function testAttachmentWithTextKeepsText(): void
    {
        $storage = $this->createStub(AttachmentStorage::class);
        $storage->method('buildBlocks')->willReturn([['type' => 'text', 'text' => '[Attached file: a.csv]']]);

        $this->controller($this->jsonRequest(['message' => 'Summarise', 'attachments' => [['id' => 3]]]), true, true, $storage)->execute();

        $this->assertSame([
            ['type' => 'text', 'text' => '[Attached file: a.csv]'],
            ['type' => 'text', 'text' => 'Summarise'],
        ], $this->userContent);
    }

    public function testCsrfValidationAndException(): void
    {
        $controller = $this->controller($this->request());

        $byParam = $this->createStub(HttpRequest::class);
        $byParam->method('getParam')->willReturn('secret');
        $byBody = $this->createStub(HttpRequest::class);
        $byBody->method('getContent')->willReturn('{"form_key":"secret"}');
        $wrong = $this->createStub(HttpRequest::class);
        $wrong->method('getContent')->willReturn('{"form_key":"x"}');

        $this->assertTrue($controller->validateForCsrf($byParam));
        $this->assertTrue($controller->validateForCsrf($byBody));
        $this->assertFalse($controller->validateForCsrf($wrong));
        $this->assertFalse($controller->validateForCsrf($this->createStub(HttpRequest::class)));

        $this->assertInstanceOf(InvalidRequestException::class, $controller->createCsrfValidationException($wrong));
        $this->assertSame(403, $this->code);
        $this->assertFalse($this->data['success']);
    }

    public function testUrlKeyCheckEnforcesFormKey(): void
    {
        $valid = $this->controller($this->jsonRequest(['message' => 'hi', 'form_key' => 'secret']));
        $this->assertTrue($valid->_processUrlKeys());

        $missing = $this->controller($this->jsonRequest(['message' => 'hi']));
        $this->assertFalse($missing->_processUrlKeys());
        $this->assertSame(403, $this->code);

        $wrong = $this->controller($this->request(['form_key' => 'guess']));
        $this->assertFalse($wrong->_processUrlKeys());
        $this->assertFalse($this->data['success']);
    }
}
