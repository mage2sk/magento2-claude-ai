<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Logger;

use Monolog\Logger as MonologLogger;

/**
 * Module-scoped logger writing to var/log/panth_claudeai.log so chat
 * events and tool errors are easy to grep without scanning system.log.
 */
class Logger extends MonologLogger
{
}
