<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model;

use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\App\CacheInterface;

class RateLimiter
{
    private const CACHE_PREFIX = 'panth_claudeai_rate_';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly AdminSession $adminSession,
        private readonly Config $config
    ) {
    }

    public function hit(): bool
    {
        $limit = $this->config->getAdminRateLimit();
        $key = self::CACHE_PREFIX . $this->userId() . '_' . gmdate('YmdH');
        $count = (int) $this->cache->load($key);
        if ($count >= $limit) {
            return false;
        }
        $this->cache->save((string) ($count + 1), $key, [], 3600);
        return true;
    }

    private function userId(): int
    {
        try {
            $user = $this->adminSession->getUser();
            return $user ? (int) $user->getId() : 0;
        } catch (\Throwable) {
            return 0;
        }
    }
}
