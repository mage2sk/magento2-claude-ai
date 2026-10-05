<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use Panth\ClaudeAi\Model\ClaudeClient;
use Panth\ClaudeAi\Model\Config;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ClaudeClientTest extends TestCase
{
    private function client(string $apiKey = ''): ClaudeClient
    {
        $config = $this->createStub(Config::class);
        $config->method('getApiKey')->willReturn($apiKey);
        return new ClaudeClient($config, $this->createStub(LoggerInterface::class));
    }

    private function normalize(array $messages): array
    {
        $method = new \ReflectionMethod(ClaudeClient::class, 'normalizeMessages');
        return $method->invoke($this->client(), $messages);
    }

    public function testMissingApiKeyThrowsBeforeAnyRequest(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('API key is not configured');
        $this->client('')->send([['role' => 'user', 'content' => 'hi']], 'system');
    }

    public function testEmptyToolInputBecomesObject(): void
    {
        $out = $this->normalize([
            ['role' => 'assistant', 'content' => [
                ['type' => 'tool_use', 'id' => 'a', 'name' => 'x', 'input' => []],
                ['type' => 'tool_use', 'id' => 'b', 'name' => 'y'],
                ['type' => 'tool_use', 'id' => 'c', 'name' => 'z', 'input' => null],
            ]],
        ]);

        foreach ($out[0]['content'] as $block) {
            $this->assertInstanceOf(\stdClass::class, $block['input']);
            $this->assertSame('{}', json_encode($block['input']));
        }
    }

    public function testListToolInputBecomesObjectAndMapIsKept(): void
    {
        $out = $this->normalize([
            ['role' => 'assistant', 'content' => [
                ['type' => 'tool_use', 'id' => 'a', 'name' => 'x', 'input' => ['p', 'q']],
                ['type' => 'tool_use', 'id' => 'b', 'name' => 'y', 'input' => ['sku' => 'MH01']],
            ]],
        ]);

        $this->assertSame('{"0":"p","1":"q"}', json_encode($out[0]['content'][0]['input']));
        $this->assertSame(['sku' => 'MH01'], $out[0]['content'][1]['input']);
    }

    public function testOtherMessagesAreUntouched(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'plain text'],
            ['role' => 'user', 'content' => ''],
            'garbage',
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'hi'], 'raw']],
        ];

        $this->assertSame($messages, $this->normalize($messages));
    }
}
