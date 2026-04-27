# Magento 2 Automation with Claude AI

> **Run your store in plain English.** Ask Claude AI to update prices, query the catalog, surface customer insights, or audit low stock — and it executes the action against your live Magento 2 store via tool use. Designed for non-technical merchants. Every change is undoable. Built on Anthropic's [Claude Opus 4.7](https://platform.claude.com/) with prompt caching, adaptive thinking, and a manual tool-use loop.

[![Magento 2.4.4 — 2.4.8](https://img.shields.io/badge/Magento-2.4.4%20--%202.4.8-orange?logo=magento&logoColor=white)](https://magento.com)
[![PHP 8.1 — 8.4](https://img.shields.io/badge/PHP-8.1%20--%208.4-blue?logo=php&logoColor=white)](https://php.net)
[![Hyvä + Luma](https://img.shields.io/badge/Themes-Hyv%C3%A4%20%2B%20Luma-14b8a6)]()
[![Open Source](https://img.shields.io/badge/Open%20Source-100%25-green)](https://github.com/mage2sk/magento2-claude-ai)

---

## What it does

Claude AI sits inside the admin like a colleague who's read every Magento docs page. You ask in plain English; it figures out which catalog, customer, order, or inventory operation you mean, runs it, and gives you back a one-paragraph summary with an undo link.

**Examples that work today:**

- *"How many products do I have?"*
- *"Show me the 10 most recent orders."*
- *"Find all products with the word 'hoodie' in the name."*
- *"Which products have less than 3 in stock?"*
- *"Make every t-shirt cost $24.99"* → previewed first, undoable.
- *"Disable all products with no stock"* → undoable.
- *"Increase prices in the Sale category by 5%"* → undoable.
- *"Reindex the catalog price index and flush the full-page cache"*
- *"undo"* → reverts the last bulk change.

---

## Highlights

| | |
|---|---|
| 🧠 **Plain-English chat** | No SKU patterns, no JSON. The AI translates "all t-shirts" → catalog query for you. |
| ⏪ **Undo everything** | Every bulk write snapshots before-state into a checkpoint. One-click rollback in admin or just say "undo". |
| 🛡️ **Dry-run by default** | New installs ship in safe mode — the AI shows what it WOULD change without touching data. Toggle off when ready. |
| 🎓 **Train your AI** | Add few-shot examples (your store's slang, conventions) → Claude follows your patterns, not its defaults. |
| 🛒 **Storefront widget** | Read-only "Ask AI" widget for shoppers. Drop it into any CMS page. Per-IP rate-limited. |
| 📁 **File uploads** | Attach images, PDFs, spreadsheets to chat. Hardened (ext+MIME+magic-byte allowlists, .htaccess guard). |
| 🪵 **Activity log + custom logger** | Every prompt, tool call, and reply persisted with timing + token usage. Tail `var/log/panth_claudeai.log` for ops. |
| ⚙️ **6 config groups, 25+ options** | Master switch, model, effort, dry-run, bulk caps, rate limits, per-tool toggles, retention windows. |
| 💻 **CLI commands** | `panth_claudeai:status`, `panth_claudeai:test-api`. |
| ⏰ **Cron cleanup** | Nightly prune of old activity rows + expired checkpoints (configurable retention). |

---

## How it works

```
Admin types: "Make all t-shirts $24.99"
        ↓
[ Send AJAX ]
        ↓
Orchestrator (manual tool-use loop)
        ├─ Inject system prompt + training examples (cached)
        ├─ Send to Anthropic /v1/messages with tool catalog
        ├─ stop_reason == tool_use ?
        │    yes → execute tool locally, snapshot before-state, append result, repeat
        │    no  → final text reply
        └─ Log every step into panth_claudeai_activity
        ↓
Admin sees: "Updated 42 t-shirts to $24.99. Reply 'undo' to revert."
```

---

## Tools (full Magento depth)

Each tool can be **enabled/disabled individually** in admin → Configuration → Tool Capabilities. Disabled tools are removed from the AI's catalog at API call time.

| Tool | What it does |
|---|---|
| `get_products` | Search by SKU pattern, name, price range |
| `update_product_price` | Bulk update prices (fixed or percent), with checkpoint |
| `update_product_status` | Enable/disable products in bulk, with checkpoint |
| `update_inventory` | Set stock qty (absolute or delta) + in-stock flag, with checkpoint |
| `store_insights` | Customer count, order count, by-status, recent orders |
| `get_low_stock_products` | Find products at/below threshold |
| `store_info` | Currency, country, base URL, Magento version |
| `cache_reindex` | Flush specific cache types, run specific indexers, list available |
| `restore_checkpoint` | Undo any prior bulk write by checkpoint ID |

**Adding a new tool:** implement `Panth\ClaudeAi\Model\Tool\ToolInterface` and add one entry to `etc/di.xml`. The model receives the new schema on the next request.

---

## Storefront Shop Assistant widget

A read-only chatbot for your shoppers — answers product/store questions in plain English. The storefront catalog is restricted to `get_products` + `store_info` only; writes are unreachable from outside admin.

**Add via widget tool** (Content → Widgets → New) or layout XML:

```xml
<block class="Panth\ClaudeAi\Block\Widget\ShopAssistant"
       name="shop.assistant"
       before="-">
    <arguments>
        <argument name="title" xsi:type="string">Need help finding something?</argument>
        <argument name="position" xsi:type="string">floating</argument> <!-- or "inline" -->
        <argument name="primary_color" xsi:type="string">#5B5BD6</argument>
    </arguments>
</block>
```

---

## Installation

```bash
composer require mage2kishan/magento2-claude-ai
bin/magento module:enable Panth_ClaudeAi
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

Then **Stores → Configuration → Panth Extensions → Claude AI** and paste your Anthropic API key. Get one at [console.anthropic.com](https://console.anthropic.com) — new accounts get $5 free credit (≈ 5,000 questions for a typical store).

The admin menu **Panth Infotech → Claude AI Automation** appears with these pages:

- **AI Dashboard** — KPIs + quick-action prompts + recent activity
- **Ask Claude** — the chat surface
- **How to Use** — 6-step plain-English onboarding (read this first)
- **Training Examples** — teach Claude your store's conventions
- **Activity Log** — full audit trail
- **Checkpoints & Restore** — one-click undo for any bulk write
- **Configuration** — 6 config groups, 25+ options

---

## Configuration

| Group | Settings |
|---|---|
| **API Credentials** | Anthropic API key (encrypted) |
| **General** | Master switch, model (Opus 4.7 / Sonnet 4.6 / Haiku 4.5), effort (low → max), max tokens, max iterations, API timeout |
| **Safety** | **Dry Run mode** (default ON), max items per bulk action, admin rate limit per hour |
| **Tool Capabilities** | Enable/disable each of the 9 tools individually |
| **Storefront Shop Assistant** | Enable widget, per-IP rate limit, max question length |
| **Logging & Retention** | Activity log on/off, retention days (default 90), checkpoint retention (default 30), file logger to `var/log/panth_claudeai.log` |

---

## Security

- **API key encrypted** in `core_config_data` via Magento's `Encrypted` backend model.
- **Every controller has `ADMIN_RESOURCE`** for ACL gating; create a custom role to scope access.
- **CSRF guard** on file upload, form-key validation on chat send.
- **File upload hardened** — extension allowlist, MIME allowlist, magic-byte verification on images, sanitised filenames with random suffix, max 10 MB, .htaccess drop disabling PHP execution in upload dir.
- **Storefront endpoint** — per-IP rate limit (default 30/hour), message length cap, history cap, **READ-ONLY tool catalog** wired via DI virtualType. Writes are unreachable.
- **Bulk-write hard cap** (default 500/call, configurable) prevents accidental whole-catalog mutations.
- **Auto-checkpoint** before every destructive write — defensive depth in case validation fails.
- **Defensive logger** — failures in the logger never crash the chat loop.

---

## CLI

```bash
# Show config + enabled tools + recent activity counts
bin/magento panth_claudeai:status

# Round-trip test against the API to verify your key + connectivity
bin/magento panth_claudeai:test-api
```

---

## Adding a new tool

```php
namespace YourVendor\YourModule\Model\Tool;

use Panth\ClaudeAi\Model\Tool\ToolInterface;

class MyTool implements ToolInterface
{
    public function name(): string { return 'my_tool'; }

    public function definition(): array
    {
        return [
            'name' => 'my_tool',
            'description' => 'Be specific — the AI uses this to decide when to call you.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'param' => ['type' => 'string', 'description' => 'A parameter'],
                ],
                'required' => ['param'],
            ],
        ];
    }

    public function execute(array $input): array
    {
        return [
            'status' => 'success',
            'affected_count' => 0,
            'summary' => 'Did the thing.',
        ];
    }
}
```

Register in `etc/di.xml`:

```xml
<type name="Panth\ClaudeAi\Model\ToolRegistry\Admin">
    <arguments>
        <argument name="tools" xsi:type="array">
            <item name="my_tool" xsi:type="object">YourVendor\YourModule\Model\Tool\MyTool</item>
        </argument>
    </arguments>
</type>
```

`bin/magento setup:di:compile && bin/magento cache:flush` — done.

---

## Architecture

| File | Purpose |
|---|---|
| `Model/ClaudeClient.php` | Raw cURL transport against `/v1/messages`. Sets `cache_control` on system prompt for Anthropic prompt caching. |
| `Model/Orchestrator.php` | The tool-use loop. Logs every step. Injects training examples. |
| `Model/ToolRegistry.php` | Holds registered tools, sorted by name for cache stability. Filters by per-tool config. |
| `Model/Tool/*.php` | Each tool. Implements `ToolInterface`. |
| `Model/CheckpointService.php` | Snapshots state before destructive writes; restores on demand. |
| `Model/TrainingRepository.php` | Reads active examples to inject as few-shot context. |
| `Model/Activity/Logger.php` | Inserts into `panth_claudeai_activity`. Defensive — failures don't crash the loop. |
| `Model/Stats.php` | Computes the dashboard KPIs from the activity table. |
| `Logger/Logger.php` + `Handler.php` | Custom logger writing to `var/log/panth_claudeai.log`. |
| `Block/Adminhtml/*` | Admin view models. |
| `Block/Widget/ShopAssistant.php` | Storefront widget block. |
| `Controller/Adminhtml/*` | Admin endpoints (chat send, training CRUD, checkpoint restore, file upload). |
| `Controller/Assistant/Ask.php` | Public storefront endpoint — restricted toolset, rate-limited. |
| `Cron/Cleanup.php` | Nightly housekeeping. |
| `Console/Command/*` | CLI commands. |

Database tables: `panth_claudeai_activity`, `panth_claudeai_training`, `panth_claudeai_checkpoint`, `panth_claudeai_attachment`.

---

## Author

**Designed & developed with ♥ by [Kishan Savaliya](https://kishansavaliya.com)** · [Panth Infotech](https://www.upwork.com/agencies/1881421506131960778/) · [Top Rated Plus on Upwork](https://www.upwork.com/freelancers/~016dd1767321100e21)

Built for Magento 2 merchants who'd rather *describe* what they want than click through 12 admin screens to do it.

---

## License

MIT.
