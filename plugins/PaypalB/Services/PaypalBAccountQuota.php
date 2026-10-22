<?php

namespace Plugin\PaypalB\Services;

use Beike\Models\Currency;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugin\PaypalB\Models\PaypalBAccount;

/**
 * 收款账号的风控限额：每日 / 每月的收款金额与笔数上限。
 *
 * 用量不另存计数器，而是从支付会话实时统计：凡已拿到 PayPal 捕获号的会话（已完成或待确认）都算一笔，
 * 金额按会话币种折算到 B 站默认币种后累加。退款、争议不冲减：风控看的是账号在 PayPal 侧产生的交易量。
 * PayPal 拒绝的捕获（DECLINED/FAILED）会清空捕获号并置为 failed，不计入。
 * 尚未捕获的进行中会话不计入，因此并发高峰下可能小幅超出上限，上限值应留出余量。
 *
 * 自然日 / 自然月按商城时区（base.app_timezone）计算：Web、桥接接口、队列与命令行都已统一到该时区，
 * paid_at / created_at 也按同一时区写入。
 */
class PaypalBAccountQuota
{
    public const PERIOD_DAY = 'day';

    public const PERIOD_MONTH = 'month';

    /**
     * 汇率与默认币种在进程内的缓存时长：队列 Worker 常驻数天，不能一直沿用启动时的汇率。
     */
    private const CACHE_SECONDS = 60;

    /** @var array<string, float>|null 币种代码 => 相对默认币种的汇率（默认币种为 1） */
    private static ?array $rates = null;

    private static string $defaultCurrency = '';

    private static int $loadedAt = 0;

    /**
     * 账号是否配置了任一限额；未配置时选择器不会发起用量查询。
     */
    public static function hasLimits(PaypalBAccount $account): bool
    {
        return self::limits($account) !== [];
    }

    /**
     * 达到任一上限时返回可读原因（用于日志和后台排查），否则返回 null。
     */
    public static function exceededReason(PaypalBAccount $account): ?string
    {
        $limits = self::limits($account);
        if ($limits === []) {
            return null;
        }

        return self::evaluate($limits, self::usage($account));
    }

    /**
     * 纯函数：把限额与用量对比，便于单元测试且不依赖数据库。
     *
     * @param array<string, float|int> $limits 键为 day_amount / day_count / month_amount / month_count
     * @param array<string, array{amount: float, count: int}> $usage 键为 day / month
     * @param string|null $currency 金额提示里的币种代码，空则取默认币种
     */
    public static function evaluate(array $limits, array $usage, ?string $currency = null): ?string
    {
        $currency ??= self::defaultCurrency();
        foreach ([self::PERIOD_DAY => '今日', self::PERIOD_MONTH => '本月'] as $period => $label) {
            $used = $usage[$period] ?? ['amount' => 0.0, 'count' => 0];
            if (isset($limits[$period . '_count']) && (int) $used['count'] >= (int) $limits[$period . '_count']) {
                return sprintf('%s已收款 %d 笔，达到上限 %d 笔。', $label, (int) $used['count'], (int) $limits[$period . '_count']);
            }
            if (isset($limits[$period . '_amount']) && (float) $used['amount'] >= (float) $limits[$period . '_amount']) {
                return sprintf(
                    '%s已收款 %s %s，达到上限 %s %s。',
                    $label,
                    number_format((float) $used['amount'], 2, '.', ''),
                    $currency,
                    number_format((float) $limits[$period . '_amount'], 2, '.', ''),
                    $currency
                );
            }
        }

        return null;
    }

    /**
     * @return array<string, float|int>
     */
    public static function limits(PaypalBAccount $account): array
    {
        $limits = [];
        foreach ([
            'day_amount'   => $account->daily_amount_limit,
            'day_count'    => $account->daily_count_limit,
            'month_amount' => $account->monthly_amount_limit,
            'month_count'  => $account->monthly_count_limit,
        ] as $key => $value) {
            if ($value !== null && $value !== '' && (float) $value > 0) {
                $limits[$key] = str_ends_with($key, '_count') ? (int) $value : (float) $value;
            }
        }

        return $limits;
    }

    /**
     * 当日与当月用量（金额已折算到默认币种）。按商城时区取自然日 / 自然月。
     *
     * @return array<string, array{amount: float, count: int}>
     */
    public static function usage(PaypalBAccount $account): array
    {
        $usage = [
            self::PERIOD_DAY   => ['amount' => 0.0, 'count' => 0],
            self::PERIOD_MONTH => ['amount' => 0.0, 'count' => 0],
        ];
        if (! $account->exists) {
            return $usage;
        }

        $monthStart = now()->startOfMonth();
        $dayStart   = now()->startOfDay();

        $rows = self::usageQuery($account, $monthStart, $dayStart)->get();

        $rates = self::rates();
        foreach ($rows as $row) {
            $amount = self::convert((float) $row->total, (string) $row->currency, $rates);
            $count  = (int) $row->cnt;
            $usage[self::PERIOD_MONTH]['amount'] += $amount;
            $usage[self::PERIOD_MONTH]['count'] += $count;
            if ((int) $row->is_today === 1) {
                $usage[self::PERIOD_DAY]['amount'] += $amount;
                $usage[self::PERIOD_DAY]['count'] += $count;
            }
        }

        return $usage;
    }

    /**
     * 本月用量查询：按币种与“是否今日”分组，一次查完日、月两个窗口。
     *
     * 计入条件是拿到了 PayPal 捕获号：PayPal 拒绝的捕获（DECLINED/FAILED）会清空捕获号并置为 failed，
     * 这里再排除 failed 兜底。待确认捕获（审核、电子支票）不写 paid_at，以创建时间兜底；不用
     * COALESCE(paid_at, created_at)——函数包裹会让 (account_id, paid_at) 索引失效，拆成
     * “paid_at 区间 OR (paid_at IS NULL AND created_at 区间)”后索引可按两段范围扫描。
     */
    public static function usageQuery(
        PaypalBAccount $account,
        \DateTimeInterface $monthStart,
        \DateTimeInterface $dayStart,
        ?ConnectionInterface $connection = null
    ): Builder {
        return ($connection ?? DB::connection())->table('paypal_b_transactions')
            ->selectRaw(
                'currency, CASE WHEN paid_at >= ? OR (paid_at IS NULL AND created_at >= ?) THEN 1 ELSE 0 END AS is_today, COUNT(*) AS cnt, SUM(amount) AS total',
                [$dayStart, $dayStart]
            )
            ->where('account_id', $account->id)
            ->whereNotNull('paypal_capture_id')
            ->where('status', '<>', 'failed')
            ->where(function (Builder $query) use ($monthStart): void {
                $query->where('paid_at', '>=', $monthStart)
                    ->orWhere(function (Builder $pending) use ($monthStart): void {
                        $pending->whereNull('paid_at')->where('created_at', '>=', $monthStart);
                    });
            })
            ->groupBy('currency', 'is_today');
    }

    public static function defaultCurrency(): string
    {
        self::expireCache();
        if (self::$defaultCurrency === '') {
            self::$defaultCurrency = strtoupper((string) (system_setting('base.currency') ?: 'USD'));
            self::$loadedAt        = self::$loadedAt ?: time();
        }

        return self::$defaultCurrency;
    }

    /**
     * 按商城汇率表折算到默认币种：表中 value 为「1 默认币种 = value 该币种」。
     */
    public static function toDefaultCurrency(float $amount, string $currency): float
    {
        return self::convert($amount, $currency, self::rates());
    }

    /**
     * @param array<string, float> $rates
     */
    private static function convert(float $amount, string $currency, array $rates): float
    {
        $currency = strtoupper(trim($currency));
        if ($currency === self::defaultCurrency()) {
            return $amount;
        }

        $rate = $rates[$currency] ?? null;
        if ($rate === null || $rate <= 0) {
            // 汇率缺失时按 1:1 计入，宁可多算也不能让限额失效；同时提醒补齐币种配置。
            Log::warning('PaypalB 账号限额折算缺少汇率，按 1:1 计入。', ['currency' => $currency]);

            return $amount;
        }

        return $amount / $rate;
    }

    /**
     * @return array<string, float>
     */
    private static function rates(): array
    {
        self::expireCache();
        if (self::$rates === null) {
            self::$rates = [];
            foreach (Currency::query()->get(['code', 'value']) as $currency) {
                self::$rates[strtoupper((string) $currency->code)] = (float) $currency->value;
            }
            self::$loadedAt = self::$loadedAt ?: time();
        }

        return self::$rates;
    }

    /**
     * 超过 CACHE_SECONDS 后丢弃进程内的汇率与默认币种，下次读取时重新查库。
     */
    private static function expireCache(): void
    {
        if (self::$loadedAt !== 0 && time() - self::$loadedAt >= self::CACHE_SECONDS) {
            self::flush();
        }
    }

    /**
     * 测试与长驻进程（队列）用：清掉进程内缓存的汇率与默认币种。
     */
    public static function flush(): void
    {
        self::$rates           = null;
        self::$defaultCurrency = '';
        self::$loadedAt        = 0;
    }
}
