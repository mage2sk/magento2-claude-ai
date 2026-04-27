<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model;

use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Anthropic Messages API client over raw cURL.
 *
 * Why raw cURL: keeps the module dependency-free (no Composer SDK install
 * step inside Magento's vendor tree), and the surface we need is small —
 * a single POST to /v1/messages with optional cache_control breakpoints
 * and the manual tool-use loop driven by `stop_reason == "tool_use"`.
 *
 * Render order on the wire is `tools` → `system` → `messages`. We place
 * the `cache_control` breakpoint on the last system block so the system
 * prompt + tool list cache together. The frozen part of the system prompt
 * (the persona, the safety rules, the tool catalog summary) sits before
 * any volatile content. See shared/prompt-caching.md.
 */
class ClaudeClient
{
    private const ENDPOINT     = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION  = '2023-06-01';

    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Send a single Messages API request.
     *
     * @param array  $messages   Conversation history (alternating user/assistant)
     * @param string $system     System prompt (cached)
     * @param array  $tools      Tool definitions (input_schema in raw JSON-Schema form)
     * @return array             Decoded response body
     * @throws LocalizedException
     */
    public function send(array $messages, string $system, array $tools = []): array
    {
        $apiKey = $this->config->getApiKey();
        if ($apiKey === '') {
            throw new LocalizedException(
                __('Anthropic API key is not configured. Stores → Configuration → Panth Extensions → Claude AI.')
            );
        }

        $payload = [
            'model'      => $this->config->getModel(),
            'max_tokens' => $this->config->getMaxTokens(),
            // System prompt is an array of content blocks; cache_control on the
            // last block caches `tools` + `system` together (tools render
            // before system in the prefix).
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

        // Adaptive thinking lets Claude decide depth dynamically. On Opus 4.7
        // it's the only thinking mode (fixed budget_tokens removed). Default
        // display is "omitted" — we don't surface thinking text to the UI,
        // so we leave it omitted to save tokens on the wire.
        $payload['thinking'] = ['type' => 'adaptive'];

        // Effort controls how aggressively Claude uses tools and how deeply
        // it reasons. See shared/effort docs in claude-api skill.
        $payload['output_config'] = ['effort' => $this->config->getEffort()];

        $bodyJson = json_encode($payload, JSON_THROW_ON_ERROR);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::ENDPOINT,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $bodyJson,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->config->getApiTimeout(),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . $apiKey,
                'anthropic-version: ' . self::API_VERSION,
                'content-type: application/json',
                'accept: application/json',
            ],
        ]);

        $raw    = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

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

    /**
     * Normalise the messages array before JSON-encoding for Anthropic.
     *
     * Two correctness fixes:
     *   1. tool_use blocks: `input` must be a JSON object (`{}`) even when
     *      the tool takes no arguments. PHP can't distinguish empty assoc
     *      array `[]` from empty object `{}` on round-trip, so an empty
     *      tool input encoded naively becomes `[]`, which Anthropic rejects:
     *      "tool_use.input: Input should be a valid dictionary".
     *   2. tool_result.content sometimes round-trips as a JSON string when
     *      it should be a list/object — leave it alone if it's already
     *      structured, but force it to (object) when empty.
     *
     * Walks recursively so it handles content blocks within content blocks.
     */
    private function normalizeMessages(array $messages): array
    {
        foreach ($messages as &$msg) {
            if (!is_array($msg) || empty($msg['content'])) {
                continue;
            }
            // String content (a plain user prompt) — leave as-is.
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
                        // A list-shaped input is almost certainly a bug. Coerce
                        // to object so the API at least doesn't reject the call.
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
