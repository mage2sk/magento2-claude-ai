<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\Tool;

use Magento\Framework\Module\FullModuleList;
use Magento\Framework\Module\ModuleListInterface;

/**
 * List installed Magento modules. Read-only — never reveals secrets.
 *
 * Useful when the merchant asks "how many third-party modules do I have"
 * or "which Panth/Hyvä modules are enabled". Filters by name pattern.
 */
class GetModules implements ToolInterface
{
    public function __construct(
        private readonly FullModuleList $fullModuleList,
        private readonly ModuleListInterface $moduleList
    ) {
    }

    public function name(): string { return 'get_modules'; }

    public function definition(): array
    {
        return [
            'name' => 'get_modules',
            'description' => 'List installed Magento modules and whether each is enabled. Optional filters: vendor (e.g. "Panth", "Magento", "Hyva"), enabled_only, name_contains. Use this to answer "how many modules / how many third-party modules / is X installed".',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'vendor'        => ['type' => 'string', 'description' => 'Filter to modules whose name starts with this vendor (Panth, Magento, Hyva, etc.).'],
                    'name_contains' => ['type' => 'string', 'description' => 'Substring match on module name.'],
                    'enabled_only'  => ['type' => 'boolean', 'description' => 'When true, return only enabled modules. Default false.'],
                ],
            ],
        ];
    }

    public function execute(array $input): array
    {
        try {
            $all     = $this->fullModuleList->getAll();
            $enabled = array_flip($this->moduleList->getNames());

            $vendor       = trim((string) ($input['vendor'] ?? ''));
            $nameContains = trim((string) ($input['name_contains'] ?? ''));
            $enabledOnly  = (bool) ($input['enabled_only'] ?? false);

            $rows = [];
            $thirdParty = 0;
            $magento = 0;
            foreach ($all as $name => $info) {
                $isEnabled = isset($enabled[$name]);
                if ($enabledOnly && !$isEnabled) {
                    continue;
                }
                if ($vendor !== '' && stripos($name, $vendor . '_') !== 0) {
                    continue;
                }
                if ($nameContains !== '' && stripos($name, $nameContains) === false) {
                    continue;
                }
                $isMagento = (str_starts_with($name, 'Magento_') || str_starts_with($name, 'Hyva_'));
                if ($isMagento) {
                    $magento++;
                } else {
                    $thirdParty++;
                }
                $rows[] = [
                    'name'    => $name,
                    'enabled' => $isEnabled,
                ];
            }

            return [
                'status'         => 'success',
                'affected_count' => count($rows),
                'modules'        => array_slice($rows, 0, 200),
                'total'          => count($rows),
                'third_party'    => $thirdParty,
                'magento_or_hyva'=> $magento,
                'summary'        => sprintf(
                    '%d modules match (%d Magento/Hyvä, %d third-party).',
                    count($rows), $magento, $thirdParty
                ),
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}
