<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model;

/**
 * Token-pricing helper. Source of truth: platform.claude.com/docs pricing.
 *
 *   - input_tokens      → full input rate
 *   - cache_read        → 0.10× input rate (90% off)
 *   - cache_creation    → 1.25× input rate (5-min TTL)
 *   - output_tokens     → output rate
 *
 * Rates are USD per 1M tokens. Kept here as a static table so we don't
 * need a network call to compute cost; ship a release whenever Anthropic
 * moves prices.
 */
class Pricing
{
    /** @var array<string,array{in:float,out:float}> USD per 1M tokens */
    private const RATES = [
        'claude-opus-4-7'   => ['in' => 5.00, 'out' => 25.00],
        'claude-opus-4-6'   => ['in' => 5.00, 'out' => 25.00],
        'claude-opus-4-5'   => ['in' => 5.00, 'out' => 25.00],
        'claude-sonnet-4-6' => ['in' => 3.00, 'out' => 15.00],
        'claude-sonnet-4-5' => ['in' => 3.00, 'out' => 15.00],
        'claude-haiku-4-5'  => ['in' => 1.00, 'out' =>  5.00],
    ];

    /** Compute cost in USD for one usage record from a Messages API response. */
    public function costFor(
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $cacheReadTokens = 0,
        int $cacheCreationTokens = 0
    ): float {
        $rate = self::RATES[$model] ?? self::RATES['claude-opus-4-7'];
        // input_tokens is the "uncached" remainder — already excludes cached portion
        $cost = ($inputTokens         * $rate['in'])  / 1_000_000;
        $cost += ($cacheReadTokens    * $rate['in'] * 0.10) / 1_000_000;
        $cost += ($cacheCreationTokens * $rate['in'] * 1.25) / 1_000_000;
        $cost += ($outputTokens       * $rate['out']) / 1_000_000;
        return round($cost, 6);
    }

    /** Pretty-print a USD amount. <$0.01 shows in tenths of a cent. */
    public function format(float $usd): string
    {
        if ($usd <= 0) {
            return '$0.00';
        }
        if ($usd < 0.01) {
            return '$' . number_format($usd, 4);
        }
        if ($usd < 1) {
            return '$' . number_format($usd, 3);
        }
        return '$' . number_format($usd, 2);
    }

    /** @return array<string,array{in:float,out:float}> */
    public function rates(): array { return self::RATES; }
}
