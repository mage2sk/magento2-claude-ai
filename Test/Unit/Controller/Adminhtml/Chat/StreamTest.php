<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Controller\Adminhtml\Chat;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Panth\ClaudeAi\Controller\Adminhtml\Chat\Stream;
use Panth\ClaudeAi\Model\AttachmentStorage;
use Panth\ClaudeAi\Model\Config;
use Panth\ClaudeAi\Model\ConversationHistory;
use Panth\ClaudeAi\Model\Orchestrator;
use Panth\ClaudeAi\Model\Pricing;
use Panth\ClaudeAi\Model\RateLimiter;
use Panth\ClaudeAi\Model\WriteConfirmation;
use Panth\ClaudeAi\Test\Unit\Controller\Adminhtml\ContextBuilderTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StreamTest extends TestCase
{
    use ContextBuilderTrait;

    private ?int $code = null;
    private ?string $contents = null;

    private function controller(): Stream
    {
        $raw = $this->createStub(Raw::class);
        $raw->method('setHttpResponseCode')->willReturnCallback(function ($code) use ($raw) {
            $this->code = $code;
            return $raw;
        });
        $raw->method('setContents')->willReturnCallback(function ($contents) use ($raw) {
            $this->contents = $contents;
            return $raw;
        });
        $rawFactory = $this->createStub(RawFactory::class);
        $rawFactory->method('create')->willReturn($raw);

        return new Stream(
            $this->buildContext($this->request(), 7, 'secret'),
            $rawFactory,
            $this->createStub(Config::class),
            $this->createStub(Orchestrator::class),
            new Pricing(),
            $this->createStub(LoggerInterface::class),
            $this->createStub(RateLimiter::class),
            $this->createStub(WriteConfirmation::class),
            $this->createStub(ConversationHistory::class),
            $this->createStub(AttachmentStorage::class),
            $this->formKeyStub()
        );
    }

    #[DataProvider('csrfProvider')]
    public function testCsrfValidation(?string $param, string $body, bool $expected): void
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('getParam')->willReturn($param);
        $request->method('getContent')->willReturn($body);

        $this->assertSame($expected, $this->controller()->validateForCsrf($request));
    }

    public static function csrfProvider(): array
    {
        return [
            'param matches' => ['secret', '', true],
            'param mismatch' => ['nope', '', false],
            'json body key' => [null, '{"form_key":"secret","message":"hi"}', true],
            'json body wrong key' => [null, '{"form_key":"nope"}', false],
            'json without key' => [null, '{"message":"hi"}', false],
            'invalid json' => [null, 'form_key=secret', false],
            'nothing' => [null, '', false],
        ];
    }

    public function testCsrfExceptionIsServerSentErrorEvent(): void
    {
        $controller = $this->controller();
        $exception = $controller->createCsrfValidationException($this->createStub(HttpRequest::class));

        $this->assertInstanceOf(InvalidRequestException::class, $exception);
        $this->assertSame(403, $this->code);
        $this->assertSame("event: error\ndata: {\"message\":\"session expired\"}\n\n", $this->contents);
        $this->assertFalse($controller->_processUrlKeys());
    }
}
