<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model;

use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\App\CacheInterface;

class WriteConfirmation
{
    private const CACHE_PREFIX = 'panth_claudeai_confirm_';
    private const LIFETIME = 1800;

    private string $userMessage = '';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly AdminSession $adminSession,
        private readonly Config $config
    ) {
    }

    public function setUserMessage(string $message): void
    {
        $this->userMessage = $message;
    }

    public function check(string $tool, array $skus, array $params): ?array
    {
        if (!$this->config->isConfirmationRequired() || $this->config->isDryRun()) {
            return null;
        }
        $threshold = $this->config->getConfirmationThreshold();
        if (count($skus) <= $threshold) {
            return null;
        }

        $normalized = array_values(array_unique(array_map('strval', $skus)));
        sort($normalized, SORT_STRING);
        ksort($params);
        $fingerprint = hash('sha256', (string) json_encode([$tool, $normalized, $params]));
        $key = self::CACHE_PREFIX . $this->userId() . '_' . $fingerprint;

        $code = (string) $this->cache->load($key);
        if ($code !== '' && $this->messageHasCode($code)) {
            $this->cache->remove($key);
            return null;
        }
        if ($code === '') {
            $code = strtoupper(bin2hex(random_bytes(3)));
            $this->cache->save($code, $key, [], self::LIFETIME);
        }

        return [
            'status'            => 'needs_confirmation',
            'affected_count'    => 0,
            'matched'           => count($normalized),
            'sample_skus'       => array_slice($normalized, 0, 5),
            'confirmation_code' => $code,
            'message'           => sprintf(
                'This change affects %d products, above the confirmation threshold of %d. Nothing was changed. '
                . 'Show the merchant the count and sample SKUs and ask them to reply with "CONFIRM %s" if they want it applied. '
                . 'The change only runs when the merchant\'s own next message contains that code and this tool is called again with the same arguments.',
                count($normalized),
                $threshold,
                $code
            ),
        ];
    }

    private function messageHasCode(string $code): bool
    {
        return $this->userMessage !== ''
            && preg_match('/\b' . preg_quote($code, '/') . '\b/i', $this->userMessage) === 1;
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
