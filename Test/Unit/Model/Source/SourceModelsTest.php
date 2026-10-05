<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model\Source;

use Panth\ClaudeAi\Model\Pricing;
use Panth\ClaudeAi\Model\Source\Effort;
use Panth\ClaudeAi\Model\Source\LauncherPosition;
use Panth\ClaudeAi\Model\Source\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SourceModelsTest extends TestCase
{
    #[DataProvider('sourceProvider')]
    public function testOptionValues(string $class, array $values): void
    {
        $options = (new $class())->toOptionArray();

        $this->assertSame($values, array_column($options, 'value'));
        foreach ($options as $option) {
            $this->assertNotSame('', (string) $option['label']);
        }
    }

    public static function sourceProvider(): array
    {
        return [
            'effort' => [Effort::class, ['low', 'medium', 'high', 'xhigh', 'max']],
            'launcher' => [LauncherPosition::class, ['header', 'floating']],
            'model' => [Model::class, ['claude-opus-4-7', 'claude-opus-4-6', 'claude-sonnet-4-6', 'claude-haiku-4-5']],
        ];
    }

    public function testEverySelectableModelHasAPrice(): void
    {
        $rates = (new Pricing())->rates();
        foreach ((new Model())->toOptionArray() as $option) {
            $this->assertArrayHasKey($option['value'], $rates);
        }
    }
}
