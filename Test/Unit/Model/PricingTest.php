<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Test\Unit\Model;

use Panth\ClaudeAi\Model\Pricing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PricingTest extends TestCase
{
    public function testCostForKnownModel(): void
    {
        $this->assertSame(18.0, (new Pricing())->costFor('claude-sonnet-4-6', 1_000_000, 1_000_000));
    }

    public function testCacheTokensUseDiscountAndPremium(): void
    {
        $pricing = new Pricing();
        $this->assertSame(0.5, $pricing->costFor('claude-opus-4-7', 0, 0, 1_000_000));
        $this->assertSame(6.25, $pricing->costFor('claude-opus-4-7', 0, 0, 0, 1_000_000));
    }

    public function testUnknownModelFallsBackToDefaultRate(): void
    {
        $pricing = new Pricing();
        $this->assertSame(
            $pricing->costFor('claude-opus-4-7', 1234, 567),
            $pricing->costFor('no-such-model', 1234, 567)
        );
    }

    public function testCostIsRoundedToSixDecimals(): void
    {
        $this->assertSame(0.000006, (new Pricing())->costFor('claude-haiku-4-5', 6, 0));
    }

    #[DataProvider('formatProvider')]
    public function testFormat(float $usd, string $expected): void
    {
        $this->assertSame($expected, (new Pricing())->format($usd));
    }

    public static function formatProvider(): array
    {
        return [
            'zero' => [0.0, '$0.00'],
            'negative' => [-1.0, '$0.00'],
            'sub cent' => [0.00123, '$0.0012'],
            'sub dollar' => [0.4567, '$0.457'],
            'dollars' => [1234.5, '$1,234.50'],
        ];
    }

    public function testRatesExposeEveryModel(): void
    {
        $rates = (new Pricing())->rates();
        $this->assertArrayHasKey('claude-opus-4-7', $rates);
        $this->assertSame(['in' => 1.00, 'out' => 5.00], $rates['claude-haiku-4-5']);
    }
}
