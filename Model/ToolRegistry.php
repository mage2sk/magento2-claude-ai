<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model;

use Panth\ClaudeAi\Model\Tool\ToolInterface;

/**
 * Holds the set of tools Claude may call. Tools are registered via di.xml
 * so adding a new capability is one DI entry, not a code edit here.
 *
 * Filters out tools the merchant has disabled in the config (per-tool toggle).
 * The remaining set is sorted by name for cache stability — see
 * shared/prompt-caching.md.
 */
class ToolRegistry
{
    /** @var array<string,ToolInterface> */
    private array $tools = [];

    /**
     * @param ToolInterface[] $tools
     * @param Config|null     $config Optional — when null all tools are enabled.
     */
    public function __construct(array $tools = [], private readonly ?Config $config = null)
    {
        foreach ($tools as $tool) {
            if ($tool instanceof ToolInterface) {
                $this->tools[$tool->name()] = $tool;
            }
        }
        ksort($this->tools);
    }

    /** Filter view: only tools enabled in config. */
    public function enabled(): array
    {
        if ($this->config === null) {
            return $this->tools;
        }
        $out = [];
        foreach ($this->tools as $name => $tool) {
            if ($this->config->isToolEnabled($name)) {
                $out[$name] = $tool;
            }
        }
        return $out;
    }

    /** Full set, ignoring config (used by the help/training pages). */
    public function all(): array
    {
        return $this->tools;
    }

    public function has(string $name): bool
    {
        return isset($this->enabled()[$name]);
    }

    public function get(string $name): ?ToolInterface
    {
        $enabled = $this->enabled();
        return $enabled[$name] ?? null;
    }

    /** @return array Wire-format tool definitions for enabled tools only. */
    public function definitions(): array
    {
        $defs = [];
        foreach ($this->enabled() as $tool) {
            $defs[] = $tool->definition();
        }
        return $defs;
    }
}
