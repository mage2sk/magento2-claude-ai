<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model;

use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

class ClaudeClient
{
    private const ENDPOINT     = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION  = '2023-06-01';

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function send(array $messages, string $system, array $tools = []): array
    {
        $apiKey = $this->config->getApiKey();
        if ($apiKey === '') {
            throw new LocalizedException(
                __('Anthropic API key is not configured. Stores -> Configuration -> Panth Extensions -> Claude AI.')
            );
        }

        $bodyJson = json_encode($this->buildPayload($messages, $system, $tools), JSON_THROW_ON_ERROR);

        $result = $this->transport($bodyJson, $apiKey, $this->config->getApiTimeout());

        return $this->parseResponse($result['raw'], (int) $result['status'], (string) $result['error']);
    }

    private function buildPayload(array $messages, string $system, array $tools): array
    {
        $payload = [
            'model'      => $this->config->getModel(),
            'max_tokens' => $this->config->getMaxTokens(),

            'system'     => [[
                'type' => 'text',
                'text' => $system,
                'cache_control' => ['type' => 'ephemeral'],
            ]],
            'messages'   => $this->normalizeMessages($messages),
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
        }

        $payload['thinking'] = ['type' => 'adaptive'];

        $payload['output_config'] = ['effort' => $this->config->getEffort()];

        return $payload;
    }

    protected function transport(string $bodyJson, string $apiKey, int $timeout): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::ENDPOINT,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $bodyJson,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => $this->buildHeaders($apiKey),
        ]);

        $raw    = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        return ['raw' => $raw, 'status' => (int) $status, 'error' => (string) $err];
    }

    private function buildHeaders(string $apiKey): array
    {
        return [
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . self::API_VERSION,
            'content-type: application/json',
            'accept: application/json',
        ];
    }

    private function parseResponse(string|bool $raw, int $status, string $err): array
    {
        if ($raw === false) {
            $this->logger->error('[panth_claudeai] cURL transport failure: ' . $err);
            throw new LocalizedException(__('Could not reach the Anthropic API: %1', $err));
        }

        $decoded = json_decode((string) $raw, true);

        if ($status >= 400 || !is_array($decoded)) {
            $errorType = $decoded['error']['type'] ?? 'http_' . $status;
            $errorMsg  = $decoded['error']['message'] ?? (string) $raw;
            $this->logger->error('[panth_claudeai] API error ' . $status . ': ' . $errorMsg);
            throw new LocalizedException(__('Claude API error (%1): %2', $errorType, $errorMsg));
        }

        return $decoded;
    }

    private function normalizeMessages(array $messages): array
    {
        foreach ($messages as &$msg) {
            if (!is_array($msg) || empty($msg['content'])) {
                continue;
            }

            if (is_string($msg['content'])) {
                continue;
            }
            foreach ($msg['content'] as &$block) {
                if (!is_array($block)) {
                    continue;
                }
                if (($block['type'] ?? null) === 'tool_use') {
                    if (!isset($block['input']) || $block['input'] === [] || $block['input'] === null) {
                        $block['input'] = (object) [];
                    } elseif (is_array($block['input']) && $this->isList($block['input'])) {
                        $block['input'] = (object) $block['input'];
                    }
                }
            }
            unset($block);
        }
        unset($msg);
        return $messages;
    }

    private function isList(array $a): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($a);
        }
        if ($a === []) {
            return true;
        }
        return array_keys($a) === range(0, count($a) - 1);
    }
}
