<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Logger;

use Magento\Framework\Filesystem\DriverInterface;
use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;
use Panth\ClaudeAi\Model\Config;

class Handler extends Base
{
    protected $loggerType = MonologLogger::INFO;

    protected $fileName = '/var/log/panth_claudeai.log';

    public function __construct(
        DriverInterface $filesystem,
        private readonly Config $config,
        ?string $filePath = null,
        ?string $fileName = null,
        private readonly bool $fallback = false
    ) {
        parent::__construct($filesystem, $filePath, $fileName);
    }

    public function isHandling(LogRecord|array $record): bool
    {
        if (!parent::isHandling($record)) {
            return false;
        }
        try {
            $enabled = $this->config->isFileLogEnabled();
        } catch (\Throwable) {
            $enabled = false;
        }
        return $this->fallback ? !$enabled : $enabled;
    }
}
