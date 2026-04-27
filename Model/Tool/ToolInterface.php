<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\Tool;

/**
 * One Magento operation Claude can invoke.
 *
 * Each tool exposes:
 *   - name()         — stable identifier (used in tool_use blocks)
 *   - definition()   — JSON-Schema definition sent in the API `tools` array
 *   - execute(input) — runs the operation, returns a structured result array
 *
 * The result array shape is up to each tool, but it MUST be JSON-serializable
 * and SHOULD include human-readable summary fields the model can echo back to
 * the user (e.g. `affected_count`, `summary`).
 */
interface ToolInterface
{
    public function name(): string;

    /**
     * @return array Anthropic tool definition: name, description, input_schema.
     */
    public function definition(): array;

    /**
     * @param array $input Parsed input from Claude (already decoded JSON)
     * @return array       Result payload returned to Claude as a tool_result
     */
    public function execute(array $input): array;
}
