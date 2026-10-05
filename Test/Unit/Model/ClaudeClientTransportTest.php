<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use Panth\ClaudeAi\Model\ClaudeClient;
use Panth\ClaudeAi\Model\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ClaudeClientTransportTest extends TestCase
{
    private const KEY = 'test-key-not-real-0000';

    private array $logged = [];

    private function config(): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('getApiKey')->willReturn(self::KEY);
        $config->method('getModel')->willReturn('model-x');
        $config->method('getMaxTokens')->willReturn(4096);
        $config->method('getEffort')->willReturn('medium');
        $config->method('getApiTimeout')->willReturn(42);
        return $config;
    }

    private function logger(): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function ($message) {
            $this->logged[] = (string) $message;
        });
        return $logger;
    }

    private function client(array $response): ClaudeClient
    {
        return new class($this->config(), $this->logger(), $response) extends ClaudeClient {
            public array $calls = [];

            public function __construct(Config $config, LoggerInterface $logger, private array $response)
            {
                parent::__construct($config, $logger);
            }

            protected function transport(string $bodyJson, string $apiKey, int $timeout): array
            {
                $this->calls[] = ['body' => $bodyJson, 'key' => $apiKey, 'timeout' => $timeout];
                return $this->response;
            }
        };
    }

    private function ok(array $body): array
    {
        return ['raw' => json_encode($body), 'status' => 200, 'error' => ''];
    }

    public function testPayloadCarriesConfiguredModelTokensEffortAndCachedSystemPrompt(): void
    {
        $client = $this->client($this->ok(['content' => []]));
        $client->send([['role' => 'user', 'content' => 'hello']], 'be helpful');

        $this->assertCount(1, $client->calls);
        $body = json_decode($client->calls[0]['body'], true);
        $this->assertSame('model-x', $body['model']);
        $this->assertSame(4096, $body['max_tokens']);
        $this->assertSame(['effort' => 'medium'], $body['output_config']);
        $this->assertSame(['type' => 'adaptive'], $body['thinking']);
        $this->assertSame('be helpful', $body['system'][0]['text']);
        $this->assertSame(['type' => 'ephemeral'], $body['system'][0]['cache_control']);
        $this->assertSame([['role' => 'user', 'content' => 'hello']], $body['messages']);
        $this->assertArrayNotHasKey('tools', $body);
    }

    public function testToolsAreSentOnlyWhenGivenAndToolInputIsAnObject(): void
    {
        $client = $this->client($this->ok(['content' => []]));
        $tools = [['name' => 'get_products', 'input_schema' => ['type' => 'object']]];
        $client->send(
            [['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 't', 'name' => 'n', 'input' => []]]]],
            's',
            $tools
        );

        $this->assertStringContainsString('"input":{}', $client->calls[0]['body']);
        $this->assertSame($tools, json_decode($client->calls[0]['body'], true)['tools']);
    }

    public function testKeyAndTimeoutGoToTransportButNotIntoTheBody(): void
    {
        $client = $this->client($this->ok(['id' => 'msg_1']));
        $client->send([['role' => 'user', 'content' => 'x']], 's');

        $this->assertSame(self::KEY, $client->calls[0]['key']);
        $this->assertSame(42, $client->calls[0]['timeout']);
        $this->assertStringNotContainsString(self::KEY, $client->calls[0]['body']);
    }

    public function testHeadersContainKeyVersionAndJsonContentType(): void
    {
        $method = new \ReflectionMethod(ClaudeClient::class, 'buildHeaders');
        $headers = $method->invoke($this->client($this->ok([])), 'abc');

        $this->assertContains('x-api-key: abc', $headers);
        $this->assertContains('anthropic-version: 2023-06-01', $headers);
        $this->assertContains('content-type: application/json', $headers);
        $this->assertContains('accept: application/json', $headers);
    }

    public function testSuccessfulResponseIsDecoded(): void
    {
        $reply = ['id' => 'msg_1', 'content' => [['type' => 'text', 'text' => 'hi']], 'usage' => ['input_tokens' => 3]];
        $this->assertSame($reply, $this->client($this->ok($reply))->send([['role' => 'user', 'content' => 'x']], 's'));
    }

    public static function apiErrorProvider(): array
    {
        return [
            'auth'      => [401, 'authentication_error', 'invalid x-api-key'],
            'quota'     => [429, 'rate_limit_error', 'Number of requests has exceeded your rate limit'],
            'overload'  => [529, 'overloaded_error', 'Overloaded'],
            'bad input' => [400, 'invalid_request_error', 'max_tokens: too large'],
        ];
    }

    #[DataProvider('apiErrorProvider')]
    public function testApiErrorsSurfaceTypeAndMessageWithoutTheKey(int $status, string $type, string $message): void
    {
        $client = $this->client([
            'raw' => json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => $message]]),
            'status' => $status,
            'error' => '',
        ]);

        try {
            $client->send([['role' => 'user', 'content' => 'x']], 's');
            $this->fail('Expected exception');
        } catch (LocalizedException $e) {
            $this->assertSame('Claude API error (' . $type . '): ' . $message, $e->getMessage());
        }
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString((string) $status, $this->logged[0]);
        $this->assertStringNotContainsString(self::KEY, $this->logged[0]);
    }

    public function testNonJsonGatewayErrorUsesHttpStatusAsType(): void
    {
        $client = $this->client(['raw' => '<html>Bad gateway</html>', 'status' => 502, 'error' => '']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Claude API error (http_502): <html>Bad gateway</html>');
        $client->send([['role' => 'user', 'content' => 'x']], 's');
    }

    public function testNonJsonBodyWithOkStatusIsTreatedAsError(): void
    {
        $client = $this->client(['raw' => 'not json', 'status' => 200, 'error' => '']);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('http_200');
        $client->send([['role' => 'user', 'content' => 'x']], 's');
    }

    public function testTimeoutOrTransportFailureIsReportedAndLogged(): void
    {
        $client = $this->client([
            'raw' => false,
            'status' => 0,
            'error' => 'Operation timed out after 42000 milliseconds',
        ]);

        try {
            $client->send([['role' => 'user', 'content' => 'x']], 's');
            $this->fail('Expected exception');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('Could not reach', $e->getMessage());
            $this->assertStringContainsString('timed out', $e->getMessage());
        }
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('transport failure', $this->logged[0]);
        $this->assertStringNotContainsString(self::KEY, $this->logged[0]);
    }

    public function testEachSendMakesExactlyOneRequestWithoutRetry(): void
    {
        $client = $this->client(['raw' => false, 'status' => 0, 'error' => 'Could not resolve host']);
        try {
            $client->send([['role' => 'user', 'content' => 'x']], 's');
        } catch (LocalizedException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertCount(1, $client->calls);
    }
}
