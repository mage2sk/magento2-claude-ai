<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model\Tool;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface as ConfigWriterInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Panth\ClaudeAi\Model\CheckpointService;
use Panth\ClaudeAi\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Set the storefront header logo.
 *
 * Workflow:
 *   1. Take a `source_path` pointing into pub/media/panth/claudeai/
 *      (where /chat/upload stores files). Reject anything outside this dir.
 *   2. Validate it's a real image via getimagesize().
 *   3. Copy it to pub/media/logo/stores/{scope_id}/<sanitised_name>.
 *   4. Snapshot existing design/header/logo_src into a checkpoint.
 *   5. Write the new value, optionally with width / height / alt.
 *   6. Flush config cache.
 *
 * Honors dry-run + confirmation. Restores via restore_checkpoint.
 */
class SetStoreLogo implements ToolInterface
{
    private const LOGO_BASE_DIR     = 'logo';
    private const ALLOWED_SOURCE    = 'panth/claudeai';
    private const ALLOWED_IMAGETYPE = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];

    private const PATH_LOGO_SRC    = 'design/header/logo_src';
    private const PATH_LOGO_ALT    = 'design/header/logo_alt';
    private const PATH_LOGO_WIDTH  = 'design/header/logo_width';
    private const PATH_LOGO_HEIGHT = 'design/header/logo_height';

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ConfigWriterInterface $configWriter,
        private readonly StoreManagerInterface $storeManager,
        private readonly TypeListInterface $cacheTypeList,
        private readonly CheckpointService $checkpoints,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function name(): string { return 'set_store_logo'; }

    public function definition(): array
    {
        return [
            'name' => 'set_store_logo',
            'description' =>
                'Set the storefront header logo from a file the user uploaded in this chat. Pass `source_path` exactly as it was reported in the upload note ("saved at panth/claudeai/foo.png"). Optional: scope_code (store view, e.g. "luma"), alt text, width, height. Snapshots the previous logo path so the merchant can undo.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'source_path' => ['type' => 'string', 'description' => 'Relative media path of the uploaded image (e.g. panth/claudeai/foo.png). Must live under panth/claudeai/.'],
                    'scope'       => ['type' => 'string', 'enum' => ['default', 'website', 'store'], 'description' => 'Default: store when scope_code is set, else default'],
                    'scope_code'  => ['type' => 'string', 'description' => 'Store view code (e.g. "default", "luma"). Required for per-store logo.'],
                    'alt'         => ['type' => 'string', 'description' => 'Optional logo alt text.'],
                    'width'       => ['type' => 'integer', 'description' => 'Optional CSS width override.'],
                    'height'      => ['type' => 'integer', 'description' => 'Optional CSS height override.'],
                    'confirm'     => ['type' => 'boolean'],
                ],
                'required' => ['source_path'],
            ],
        ];
    }

    public function execute(array $input): array
    {
        try {
            $source = trim((string) ($input['source_path'] ?? ''));
            if ($source === '') {
                return ['status' => 'error', 'message' => 'source_path is required.'];
            }
            // Defence in depth: resolve and re-check the prefix to block any
            // ../ traversal, absolute path, or symlink trick.
            $source = ltrim(str_replace('\\', '/', $source), '/');
            if (!str_starts_with($source, self::ALLOWED_SOURCE . '/')) {
                return [
                    'status'  => 'error',
                    'message' => 'source_path must point to an upload under panth/claudeai/. Got: ' . $source,
                ];
            }

            $media = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
            $absSource = $media->getAbsolutePath($source);
            if (!is_file($absSource)) {
                return ['status' => 'error', 'message' => 'Uploaded file not found at media/' . $source];
            }
            // Defence in depth: realpath() resolves symlinks and `..`
            // segments. Even though str_starts_with("panth/claudeai/")
            // already passed, an attacker could pass
            // "panth/claudeai/../../etc/passwd" — make sure the file we
            // actually read lives inside the allowed upload dir.
            $realSource = @realpath($absSource);
            $realAllowed = @realpath($media->getAbsolutePath(self::ALLOWED_SOURCE));
            if ($realSource === false || $realAllowed === false
                || strncmp($realSource, $realAllowed . DIRECTORY_SEPARATOR, strlen($realAllowed) + 1) !== 0
            ) {
                return [
                    'status'  => 'error',
                    'message' => 'source_path resolves outside the allowed upload directory.',
                ];
            }
            $info = @getimagesize($absSource);
            if ($info === false || !in_array($info[2] ?? null, self::ALLOWED_IMAGETYPE, true)) {
                return ['status' => 'error', 'message' => 'That file is not a recognized image (jpg/png/gif/webp).'];
            }

            $scope    = (string) ($input['scope'] ?? (isset($input['scope_code']) && $input['scope_code'] !== '' ? 'store' : 'default'));
            $scopeId  = $this->resolveScopeId($scope, (string) ($input['scope_code'] ?? ''));

            // Confirmation gate (skipped for explicit confirm=true).
            if ($this->config->isConfirmationRequired() && !($input['confirm'] ?? false)) {
                return [
                    'status'  => 'needs_confirmation',
                    'message' => sprintf(
                        'About to set the store logo from %s (scope=%s id=%d, %dx%d). Re-call with confirm=true to apply.',
                        $source, $scope, $scopeId, (int) $info[0], (int) $info[1]
                    ),
                ];
            }

            // Build the destination path: logo/stores/{scope_id}/<sanitised>.
            $basename = basename($source);
            $stem     = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($basename, PATHINFO_FILENAME)) ?: 'logo';
            $ext      = strtolower(pathinfo($basename, PATHINFO_EXTENSION)) ?: 'png';
            $destRel  = self::LOGO_BASE_DIR . '/stores/' . $scopeId . '/' . $stem . '.' . $ext;
            $absDest  = $media->getAbsolutePath($destRel);
            $media->create(dirname($destRel));

            // Snapshot the existing logo paths before writing.
            $checkpointId = $this->checkpoints->snapshotConfig(
                'set_store_logo',
                [
                    ['path' => self::PATH_LOGO_SRC,    'scope' => $scope, 'scope_id' => $scopeId],
                    ['path' => self::PATH_LOGO_ALT,    'scope' => $scope, 'scope_id' => $scopeId],
                    ['path' => self::PATH_LOGO_WIDTH,  'scope' => $scope, 'scope_id' => $scopeId],
                    ['path' => self::PATH_LOGO_HEIGHT, 'scope' => $scope, 'scope_id' => $scopeId],
                ],
                sprintf('Set store logo (scope=%s id=%d) to %s', $scope, $scopeId, $destRel),
                ''
            );

            if ($this->config->isDryRun()) {
                return [
                    'status'        => 'dry_run',
                    'checkpoint_id' => $checkpointId,
                    'message'       => sprintf('[DRY RUN] Would copy %s → media/%s and set %s.', $source, $destRel, self::PATH_LOGO_SRC),
                    'preview'       => ['width' => (int) $info[0], 'height' => (int) $info[1]],
                ];
            }

            // Copy via the filesystem driver (works across the magento mount).
            $driver = $media->getDriver();
            $driver->filePutContents($absDest, $driver->fileGetContents($absSource));

            // Magento's logo_src convention: relative to media/logo/.
            $logoValue = ltrim(substr($destRel, strlen(self::LOGO_BASE_DIR) + 1), '/');
            $this->configWriter->save(self::PATH_LOGO_SRC, $logoValue, $scope, $scopeId);
            if (!empty($input['alt'])) {
                $this->configWriter->save(self::PATH_LOGO_ALT, (string) $input['alt'], $scope, $scopeId);
            }
            if (!empty($input['width'])) {
                $this->configWriter->save(self::PATH_LOGO_WIDTH, (string) (int) $input['width'], $scope, $scopeId);
            }
            if (!empty($input['height'])) {
                $this->configWriter->save(self::PATH_LOGO_HEIGHT, (string) (int) $input['height'], $scope, $scopeId);
            }
            $this->cacheTypeList->cleanType('config');
            $this->cacheTypeList->cleanType('full_page');

            return [
                'status'         => 'success',
                'affected_count' => 1,
                'checkpoint_id'  => $checkpointId,
                'logo_path'      => $logoValue,
                'media_path'     => 'media/' . $destRel,
                'dimensions'     => ['width' => (int) $info[0], 'height' => (int) $info[1]],
                'summary'        => sprintf(
                    'Logo set to %s (scope=%s id=%d, %dx%d). Frontend cache flushed. Undo: restore_checkpoint with %s',
                    $logoValue, $scope, $scopeId, (int) $info[0], (int) $info[1], $checkpointId
                ),
            ];
        } catch (\Throwable $e) {
            $this->logger->error('[panth_claudeai] set_store_logo: ' . $e->getMessage());
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
        }
        return 0;
    }
}
