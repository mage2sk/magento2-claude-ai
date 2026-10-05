<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model;

class Pricing
{
    private const RATES = [
        'claude-opus-4-7'   => ['in' => 5.00, 'out' => 25.00],
        'claude-opus-4-6'   => ['in' => 5.00, 'out' => 25.00],
        'claude-opus-4-5'   => ['in' => 5.00, 'out' => 25.00],
        'claude-sonnet-4-6' => ['in' => 3.00, 'out' => 15.00],
        'claude-sonnet-4-5' => ['in' => 3.00, 'out' => 15.00],
        'claude-haiku-4-5'  => ['in' => 1.00, 'out' =>  5.00],
    ];

    public function costFor(
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $cacheReadTokens = 0,
        int $cacheCreationTokens = 0
    ): float {
        $rate = self::RATES[$model] ?? self::RATES['claude-opus-4-7'];

        $cost = ($inputTokens         * $rate['in'])  / 1_000_000;
        $cost += ($cacheReadTokens    * $rate['in'] * 0.10) / 1_000_000;
        $cost += ($cacheCreationTokens * $rate['in'] * 1.25) / 1_000_000;
        $cost += ($outputTokens       * $rate['out']) / 1_000_000;
        return round($cost, 6);
    }

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

    public function rates(): array { return self::RATES; }
}
