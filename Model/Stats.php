<?php
declare(strict_types=1);

namespace Panth\ClaudeAi\Model;

use Magento\Framework\App\ResourceConnection;

class Stats
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Pricing $pricing,
        private readonly Config $config
    ) {
    }

    public function compute(): array
    {
        $conn  = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_claudeai_activity');

        if (!$conn->isTableExists($table)) {
            return $this->emptyStats();
        }

        $totalAutomations = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM {$table} WHERE actor_type = 'user'"
        );
        $tasksExecuted = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM {$table} WHERE actor_type = 'tool'"
        );
        $successCount = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM {$table} WHERE actor_type = 'tool' AND status = 'success'"
        );
        $successRate = $tasksExecuted > 0
            ? round(($successCount / $tasksExecuted) * 100, 1)
            : 100.0;

        $timeSavedHours = round(($totalAutomations * 3) / 60, 1);

        $todayCount = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM {$table} WHERE actor_type = 'user' AND DATE(created_at) = CURDATE()"
        );
        $monthCount = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM {$table} WHERE actor_type = 'user' "
            . "AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
        );
        $prevMonthCount = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM {$table} WHERE actor_type = 'user' "
            . "AND created_at >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH, '%Y-%m-01') "
            . "AND created_at <  DATE_FORMAT(CURDATE(), '%Y-%m-01')"
        );

        $mostUsedTools = $conn->fetchAll(
            "SELECT action, COUNT(*) AS n FROM {$table} "
            . "WHERE actor_type = 'tool' GROUP BY action ORDER BY n DESC LIMIT 5"
        );

        $recent = $conn->fetchAll(
            "SELECT entity_id, actor_type, action, prompt, result, status, "
            . "affected_count, created_at FROM {$table} "
            . "ORDER BY created_at DESC LIMIT 8"
        );

        $trend7d = $conn->fetchAll(
            "SELECT DATE(created_at) AS day, COUNT(*) AS n FROM {$table} "
            . "WHERE actor_type = 'user' "
            . "AND created_at >= CURDATE() - INTERVAL 6 DAY "
            . "GROUP BY DATE(created_at) ORDER BY day ASC"
        );

        $model = $this->config->getModel();
        $tokensRow = $conn->fetchRow(
            "SELECT COALESCE(SUM(input_tokens),0) AS in_tot, "
            . "COALESCE(SUM(output_tokens),0) AS out_tot, "
            . "COALESCE(SUM(cache_read_tokens),0) AS cache_tot "
            . "FROM {$table} WHERE actor_type = 'assistant'"
        );
        $todayTokensRow = $conn->fetchRow(
            "SELECT COALESCE(SUM(input_tokens),0) AS in_tot, "
            . "COALESCE(SUM(output_tokens),0) AS out_tot, "
            . "COALESCE(SUM(cache_read_tokens),0) AS cache_tot "
            . "FROM {$table} WHERE actor_type = 'assistant' AND DATE(created_at) = CURDATE()"
        );
        $monthTokensRow = $conn->fetchRow(
            "SELECT COALESCE(SUM(input_tokens),0) AS in_tot, "
            . "COALESCE(SUM(output_tokens),0) AS out_tot, "
            . "COALESCE(SUM(cache_read_tokens),0) AS cache_tot "
            . "FROM {$table} WHERE actor_type = 'assistant' "
            . "AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
        );

        $totalCostUsd = $this->pricing->costFor(
            $model,
            (int) $tokensRow['in_tot'],
            (int) $tokensRow['out_tot'],
            (int) $tokensRow['cache_tot']
        );
        $todayCostUsd = $this->pricing->costFor(
            $model,
            (int) $todayTokensRow['in_tot'],
            (int) $todayTokensRow['out_tot'],
            (int) $todayTokensRow['cache_tot']
        );
        $monthCostUsd = $this->pricing->costFor(
            $model,
            (int) $monthTokensRow['in_tot'],
            (int) $monthTokensRow['out_tot'],
            (int) $monthTokensRow['cache_tot']
        );

        return [
            'total_automations'   => $totalAutomations,
            'tasks_executed'      => $tasksExecuted,
            'time_saved_hours'    => $timeSavedHours,
            'success_rate'        => $successRate,
            'today_count'         => $todayCount,
            'month_count'         => $monthCount,
            'prev_month_count'    => $prevMonthCount,
            'most_used_tools'     => $mostUsedTools,
            'recent'              => $recent,
            'trend_7d'            => $trend7d,

            'total_input_tokens'  => (int) $tokensRow['in_tot'],
            'total_output_tokens' => (int) $tokensRow['out_tot'],
            'total_cache_tokens'  => (int) $tokensRow['cache_tot'],
            'total_cost_usd'      => $totalCostUsd,
            'today_cost_usd'      => $todayCostUsd,
            'month_cost_usd'      => $monthCostUsd,
            'cost_model'          => $model,
        ];
    }

    private function emptyStats(): array
    {
        return [
            'total_automations'   => 0,
            'tasks_executed'      => 0,
            'time_saved_hours'    => 0.0,
            'success_rate'        => 100.0,
            'today_count'         => 0,
            'month_count'         => 0,
            'prev_month_count'    => 0,
            'most_used_tools'     => [],
            'recent'              => [],
            'total_input_tokens'  => 0,
            'total_output_tokens' => 0,
            'total_cache_tokens'  => 0,
            'total_cost_usd'      => 0.0,
            'today_cost_usd'      => 0.0,
            'month_cost_usd'      => 0.0,
            'cost_model'          => $this->config->getModel(),
            'trend_7d'            => [],
        ];
    }
}
