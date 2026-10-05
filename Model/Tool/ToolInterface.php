<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\Tool;

interface ToolInterface
{
    public function name(): string;

    public function definition(): array;

    public function execute(array $input): array;
}
