<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\Tool;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface as ConfigWriterInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\ClaudeAi\Model\CheckpointService;
use Panth\ClaudeAi\Model\Config;

/**
 * Generic store-config writer with a strict allow-list of paths the AI
 * may touch. Anything that affects payments, taxes, shipping, security,
 * URLs, encryption, indexers, cron, or developer mode is OFF the list —
 * if the merchant needs those, they go to System → Configuration.
 *
 * Every write snapshots the previous value into a checkpoint so the
 * merchant can undo via restore_checkpoint. Honors dry-run mode.
 *
 * Action surface:
 *   - read:    return the current value at a given path/scope
 *   - write:   set a new value (allow-listed paths only)
 *   - delete:  clear a value back to inheritance/default
 */
class UpdateConfig implements ToolInterface
{
    /**
     * Paths the AI may read OR write. Lock this down hard — adding a path
     * here is the security review surface for this tool.
     */
    private const WRITE_ALLOWLIST = [
        // Branding / store identity
        'general/store_information/name',
        'general/store_information/phone',
        'general/store_information/hours',
        'general/store_information/country_id',
        'general/store_information/region_id',
        'general/store_information/postcode',
        'general/store_information/city',
        'general/store_information/street_line1',
        'general/store_information/street_line2',
        'general/store_information/merchant_vat_number',
        'general/store_information/merchant_country',
        'trans_email/ident_general/name',
        'trans_email/ident_general/email',
        'trans_email/ident_sales/name',
        'trans_email/ident_sales/email',
        'trans_email/ident_support/name',
        'trans_email/ident_support/email',
        // Header / footer
        'design/header/welcome',
        'design/header/logo_alt',
        'design/header/logo_width',
        'design/header/logo_height',
        'design/footer/copyright',
        'design/footer/absolute_footer',
        // Email logo alt + footer
        'design/email/logo_alt',
        'design/email/logo_width',
        'design/email/logo_height',
        'design/email/footer_template',
        // Locale (read-only-ish — risky to flip but allowed)
        'general/locale/timezone',
        'general/locale/weight_unit',
        'general/locale/firstday',
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ConfigWriterInterface $configWriter,
        private readonly StoreManagerInterface $storeManager,
        private readonly TypeListInterface $cacheTypeList,
        private readonly CheckpointService $checkpoints,
        private readonly Config $config
    ) {
    }

    public function name(): string { return 'update_config'; }

    public function definition(): array
    {
        return [
            'name' => 'update_config',
            'description' =>
                'Read or write whitelisted store configuration values. action="read" returns the current value at path. action="write" sets a value (creates a checkpoint for undo). action="delete" clears it back to inheritance. Allowed paths: store information (name/phone/address), header/footer copy, email sender identities, locale settings, design logo dimensions/alt. NOT allowed: payments, taxes, shipping, security, URLs, encryption — those must be done manually. scope_code: store/website code, defaults to default scope.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action'     => ['type' => 'string', 'enum' => ['read', 'write', 'delete', 'list_allowed']],
                    'path'       => ['type' => 'string', 'description' => 'Config path, e.g. general/store_information/name'],
                    'value'      => ['type' => 'string', 'description' => 'New value for write.'],
                    'scope'      => ['type' => 'string', 'enum' => ['default', 'website', 'store'], 'description' => 'Default: default'],
                    'scope_code' => ['type' => 'string', 'description' => 'Store/website code (e.g. "default", "luma_store_view"). Resolved to numeric scope_id.'],
                    'confirm'    => ['type' => 'boolean', 'description' => 'Required true for write/delete on non-test paths.'],
                ],
                'required' => ['action'],
            ],
        ];
    }

    public function execute(array $input): array
    {
        try {
            $action = (string) ($input['action'] ?? '');
            if ($action === 'list_allowed') {
                return [
                    'status'    => 'success',
                    'allowed'   => self::WRITE_ALLOWLIST,
                    'summary'   => sprintf('%d paths the AI may read or write.', count(self::WRITE_ALLOWLIST)),
                ];
            }

            $path = (string) ($input['path'] ?? '');
            if ($path === '') {
                return ['status' => 'error', 'message' => 'path is required.'];
            }

            $scope = (string) ($input['scope'] ?? 'default');
            $scopeId = $this->resolveScopeId($scope, (string) ($input['scope_code'] ?? ''));

            switch ($action) {
                case 'read': {
                    $value = $this->scopeConfig->getValue($path, $scope === 'default' ? 'default' : $scope, $scopeId ?: null);
                    return [
                        'status'  => 'success',
                        'path'    => $path,
                        'scope'   => $scope,
                        'scope_id'=> $scopeId,
                        'value'   => $value,
                        'summary' => sprintf('%s = %s (scope=%s id=%d)', $path, is_scalar($value) ? (string) $value : json_encode($value), $scope, $scopeId),
                    ];
                }

                case 'write': {
                    if (!in_array($path, self::WRITE_ALLOWLIST, true)) {
                        return [
                            'status' => 'error',
                            'message' => "Path '{$path}' is not in the writable allow-list. Use action=list_allowed to see what's permitted, or do this manually under Stores → Configuration.",
                        ];
                    }
                    if (!array_key_exists('value', $input)) {
                        return ['status' => 'error', 'message' => 'value is required for write.'];
                    }
                    if ($this->config->isConfirmationRequired() && !($input['confirm'] ?? false)) {
                        return [
                            'status'  => 'needs_confirmation',
                            'message' => "About to set {$path} (scope={$scope}, scope_id={$scopeId}). Re-call with confirm=true to apply.",
                        ];
                    }

                    $newValue = (string) $input['value'];
                    $checkpointId = $this->checkpoints->snapshotConfig(
                        'update_config',
                        [['path' => $path, 'scope' => $scope, 'scope_id' => $scopeId]],
                        sprintf('Set %s to %s', $path, mb_strimwidth($newValue, 0, 80, '…')),
                        ''
                    );
                    if ($this->config->isDryRun()) {
                        return [
                            'status'        => 'dry_run',
                            'checkpoint_id' => $checkpointId,
                            'message'       => "[DRY RUN] Would set {$path} = {$newValue}.",
                        ];
                    }
                    $this->configWriter->save($path, $newValue, $scope, $scopeId);
                    $this->cacheTypeList->cleanType('config');
                    return [
                        'status'         => 'success',
                        'checkpoint_id'  => $checkpointId,
                        'affected_count' => 1,
                        'path'           => $path,
                        'value'          => $newValue,
                        'summary'        => sprintf('Set %s = %s (scope=%s id=%d). Undo: restore_checkpoint with %s', $path, $newValue, $scope, $scopeId, $checkpointId),
                    ];
                }

                case 'delete': {
                    if (!in_array($path, self::WRITE_ALLOWLIST, true)) {
                        return ['status' => 'error', 'message' => "Path '{$path}' is not in the allow-list."];
                    }
                    if ($this->config->isConfirmationRequired() && !($input['confirm'] ?? false)) {
                        return [
                            'status'  => 'needs_confirmation',
                            'message' => "About to clear {$path} (scope={$scope}, scope_id={$scopeId}). Re-call with confirm=true.",
                        ];
                    }
                    $checkpointId = $this->checkpoints->snapshotConfig(
                        'update_config:delete',
                        [['path' => $path, 'scope' => $scope, 'scope_id' => $scopeId]],
                        sprintf('Clear %s', $path),
                        ''
                    );
                    if ($this->config->isDryRun()) {
                        return [
                            'status'        => 'dry_run',
                            'checkpoint_id' => $checkpointId,
                            'message'       => "[DRY RUN] Would clear {$path}.",
                        ];
                    }
                    $this->configWriter->delete($path, $scope, $scopeId);
                    $this->cacheTypeList->cleanType('config');
                    return [
                        'status'         => 'success',
                        'checkpoint_id'  => $checkpointId,
                        'affected_count' => 1,
                        'summary'        => sprintf('Cleared %s. Undo: restore_checkpoint with %s', $path, $checkpointId),
                    ];
                }
            }
            return ['status' => 'error', 'message' => 'Unknown action: ' . $action];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    private function resolveScopeId(string $scope, string $scopeCode): int
    {
        if ($scope === 'default' || $scopeCode === '') {
            return 0;
        }
        try {
            if ($scope === ScopeInterface::SCOPE_STORE) {
                return (int) $this->storeManager->getStore($scopeCode)->getId();
            }
            if ($scope === ScopeInterface::SCOPE_WEBSITE) {
                return (int) $this->storeManager->getWebsite($scopeCode)->getId();
            }
        } catch (\Throwable) {
            // Fall through to 0 below.
        }
        return 0;
    }
}
