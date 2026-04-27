<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const XML_API_KEY        = 'panth_claudeai/api/api_key';
    public const XML_ENABLED        = 'panth_claudeai/general/enabled';
    public const XML_MODEL          = 'panth_claudeai/general/model';
    public const XML_EFFORT         = 'panth_claudeai/general/effort';
    public const XML_MAX_TOKENS     = 'panth_claudeai/general/max_tokens';
    public const XML_MAX_ITERATIONS = 'panth_claudeai/general/max_iterations';
    public const XML_API_TIMEOUT    = 'panth_claudeai/general/api_timeout';

    public const XML_DRY_RUN        = 'panth_claudeai/safety/dry_run';
    public const XML_MAX_BULK       = 'panth_claudeai/safety/max_bulk_update';
    public const XML_RATE_ADMIN     = 'panth_claudeai/safety/rate_limit_per_hour';
    public const XML_REQUIRE_CONFIRM = 'panth_claudeai/safety/require_confirmation';
    public const XML_CONFIRM_THRESHOLD = 'panth_claudeai/safety/confirmation_threshold';

    public const XML_TOOL_PREFIX    = 'panth_claudeai/tools/';

    public const XML_LOG_ENABLED    = 'panth_claudeai/logging/enabled';
    public const XML_LOG_RETENTION  = 'panth_claudeai/logging/retention_days';
    public const XML_CHK_RETENTION  = 'panth_claudeai/logging/checkpoint_retention_days';
    public const XML_LOG_FILE       = 'panth_claudeai/logging/file_logger';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    private function get(string $path)
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE);
    }

    public function getApiKey(): string
    {
        $encrypted = (string) $this->get(self::XML_API_KEY);
        return $encrypted === '' ? '' : (string) $this->encryptor->decrypt($encrypted);
    }

    public function isEnabled(): bool { return (bool) $this->get(self::XML_ENABLED); }
    public function getModel(): string { return (string) ($this->get(self::XML_MODEL) ?: 'claude-opus-4-7'); }
    public function getEffort(): string { return (string) ($this->get(self::XML_EFFORT) ?: 'high'); }
    public function getMaxTokens(): int { return max(1024, (int) $this->get(self::XML_MAX_TOKENS) ?: 8192); }
    public function getMaxIterations(): int { return max(1, (int) $this->get(self::XML_MAX_ITERATIONS) ?: 8); }
    public function getApiTimeout(): int { return max(10, (int) ($this->get(self::XML_API_TIMEOUT) ?: 120)); }

    public function isDryRun(): bool { return (bool) $this->get(self::XML_DRY_RUN); }
    public function getMaxBulkUpdate(): int { return max(1, (int) ($this->get(self::XML_MAX_BULK) ?: 500)); }
    public function getAdminRateLimit(): int { return max(1, (int) ($this->get(self::XML_RATE_ADMIN) ?: 60)); }
    public function isConfirmationRequired(): bool
    {
        $v = $this->get(self::XML_REQUIRE_CONFIRM);
        return $v === null || (bool) $v;
    }
    public function getConfirmationThreshold(): int { return max(0, (int) ($this->get(self::XML_CONFIRM_THRESHOLD) ?? 5)); }

    /** Per-tool enable flag. Defaults to TRUE if config not set (backward compat). */
    public function isToolEnabled(string $toolName): bool
    {
        $val = $this->get(self::XML_TOOL_PREFIX . $toolName);
        if ($val === null || $val === '') {
            return true;
        }
        return (bool) $val;
    }

    public function isLoggingEnabled(): bool
    {
        $v = $this->get(self::XML_LOG_ENABLED);
        return $v === null || (bool) $v;
    }
    public function getLogRetentionDays(): int { return max(1, (int) ($this->get(self::XML_LOG_RETENTION) ?: 90)); }
    public function getCheckpointRetentionDays(): int { return max(1, (int) ($this->get(self::XML_CHK_RETENTION) ?: 30)); }
    public function isFileLogEnabled(): bool { return (bool) $this->get(self::XML_LOG_FILE); }
}
