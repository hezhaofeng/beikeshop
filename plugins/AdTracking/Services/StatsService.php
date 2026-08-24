<?php

namespace Plugin\AdTracking\Services;

use Plugin\AdTracking\Models\AdTrackingEvent;
use Throwable;

class StatsService
{
    /**
     * 生成后台总览所需数据，数据库未完成迁移时返回可渲染的空状态。
     */
    public static function dashboard(int $days = 7): array
    {
        $empty = [
            'period_days'    => $days,
            'total_events'   => 0,
            'success_events' => 0,
            'failed_events'  => 0,
            'success_rate'   => 0,
            'platforms'      => [],
            'recent_events'  => collect(),
        ];

        try {
            $start   = now()->subDays(max(1, $days) - 1)->startOfDay();
            $query   = AdTrackingEvent::query()->where('created_at', '>=', $start);
            $total   = (clone $query)->count();
            $success = (clone $query)->where('status', 'success')->count();

            $platformRows = (clone $query)
                ->selectRaw('platform, COUNT(*) as total')
                ->selectRaw("SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success")
                ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed")
                ->groupBy('platform')
                ->get()
                ->keyBy('platform');

            $platforms = collect(SettingsService::platforms())->mapWithKeys(
                function (array $definition, string $platform) use ($platformRows): array {
                    $row = $platformRows->get($platform);

                    return [$platform => [
                        'name'    => $definition['name'],
                        'total'   => (int) ($row->total ?? 0),
                        'success' => (int) ($row->success ?? 0),
                        'failed'  => (int) ($row->failed ?? 0),
                    ]];
                }
            )->all();

            return [
                'period_days'    => $days,
                'total_events'   => $total,
                'success_events' => $success,
                'failed_events'  => (clone $query)->where('status', 'failed')->count(),
                'success_rate'   => $total > 0 ? round($success / $total * 100, 1) : 0,
                'platforms'      => $platforms,
                'recent_events'  => (clone $query)
                    ->latest('id')
                    ->limit(8)
                    ->get(['event_name', 'platform', 'status', 'http_status', 'request', 'created_at', 'sent_at']),
            ];
        } catch (Throwable) {
            return $empty;
        }
    }

    /**
     * 返回平台最近事件的可读状态，避免视图直接判断数据库字段。
     */
    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'success' => 'success',
            'failed'  => 'danger',
            'sending' => 'warning',
            default   => 'secondary',
        };
    }
}
