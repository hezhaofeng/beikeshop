<?php

namespace Plugin\CyberCloak\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 保存可解释的风险事件，并提供后台人工审核状态。
 *
 * 明文 IP 只以框架加密数据保存在风险档案中，查询和关联均使用 HMAC 摘要。
 */
class TrafficRiskAuditService
{
    private const PROFILE_TABLE = 'cyber_cloak_traffic_risk_profiles';

    private const EVENT_TABLE = 'cyber_cloak_traffic_risk_events';

    /**
     * 读取指定 IP 的人工审核状态；数据表未迁移时按未审核处理。
     */
    public function profileStatus(string $ip): string
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP) || ! $this->available()) {
            return 'unreviewed';
        }

        try {
            $status = DB::table(self::PROFILE_TABLE)
                ->where('ip_hash', $this->ipHash($ip))
                ->value('review_status');

            return in_array($status, ['unreviewed', 'trusted', 'suspicious', 'blocked'], true)
                ? $status
                : 'unreviewed';
        } catch (\Throwable $exception) {
            Log::warning('CyberCloak 读取风险 IP 审核状态失败，已按未审核继续', ['exception' => $exception->getMessage()]);

            return 'unreviewed';
        }
    }

    /**
     * 只记录达到审核阈值的漏斗决策，避免常规访问造成无意义审计数据。
     *
     * @param array{action:string,stage:string,score:int,reasons:array<int,string>,signals:array<string,mixed>,ip:string} $result
     */
    public function record(Request $request, array $result, bool $enabled, int $threshold): void
    {
        $ip = (string) ($result['ip'] ?? '');
        if (! $enabled || (int) ($result['score'] ?? 0) < max(1, $threshold) || ! filter_var($ip, FILTER_VALIDATE_IP) || ! $this->available()) {
            return;
        }

        try {
            $now     = now();
            $ipHash  = $this->ipHash($ip);
            $signals = $this->sanitizeSignals((array) ($result['signals'] ?? []));
            $profile = DB::table(self::PROFILE_TABLE)->where('ip_hash', $ipHash)->first();
            $payload = [
                'encrypted_ip'  => Crypt::encryptString($ip),
                'last_score'    => min(100, max(0, (int) $result['score'])),
                'max_score'     => max((int) ($profile->max_score ?? 0), min(100, max(0, (int) $result['score']))),
                'last_action'   => substr((string) ($result['action'] ?? 'observe'), 0, 16),
                'last_reasons'  => $this->json((array) ($result['reasons'] ?? [])),
                'last_signals'  => $this->json($signals),
                'last_seen_at'  => $now,
                'updated_at'    => $now,
            ];

            if ($profile) {
                $payload['hit_count'] = (int) $profile->hit_count + 1;
                DB::table(self::PROFILE_TABLE)->where('id', $profile->id)->update($payload);
                $profileId = (int) $profile->id;
            } else {
                $profileId = (int) DB::table(self::PROFILE_TABLE)->insertGetId(array_merge($payload, [
                    'ip_hash'       => $ipHash,
                    'review_status' => 'unreviewed',
                    'hit_count'     => 1,
                    'first_seen_at' => $now,
                    'created_at'    => $now,
                ]));
            }

            DB::table(self::EVENT_TABLE)->insert([
                'profile_id'     => $profileId,
                'ip_hash'        => $ipHash,
                'score'          => min(100, max(0, (int) $result['score'])),
                'action'         => substr((string) ($result['action'] ?? 'observe'), 0, 16),
                'stage'          => substr((string) ($result['stage'] ?? 'unknown'), 0, 32),
                'reasons'        => $this->json((array) ($result['reasons'] ?? [])),
                'signals'        => $this->json($signals),
                'route'          => substr($this->routeIdentifier($request), 0, 255),
                'user_agent_hash' => hash('sha256', (string) $request->userAgent()),
                'occurred_at'    => $now,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
        } catch (\Throwable $exception) {
            // 审计库异常不能反向阻断前台请求，保留中文日志供部署侧排查。
            Log::warning('CyberCloak 写入风险流量审计失败，已跳过本次记录', ['exception' => $exception->getMessage()]);
        }
    }

    /**
     * 返回高级设置中的风险 IP 审核列表；IP 仅展示脱敏形式。
     *
     * @return array{available:bool,items:array<int,array<string,mixed>>,pagination:array<string,int>}
     */
    public function profiles(?string $status, int $page, int $perPage): array
    {
        if (! $this->available()) {
            return ['available' => false, 'items' => [], 'pagination' => ['page' => 1, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1]];
        }

        $status  = in_array($status, ['unreviewed', 'trusted', 'suspicious', 'blocked'], true) ? $status : null;
        $perPage = min(100, max(1, $perPage));

        try {
            $query = DB::table(self::PROFILE_TABLE)->orderByDesc('last_seen_at');
            if ($status !== null) {
                $query->where('review_status', $status);
            }
            $paginator = $query->paginate($perPage, ['*'], 'page', max(1, $page));

            return [
                'available' => true,
                'items' => collect($paginator->items())->map(function (object $profile): array {
                    return [
                        'id'            => (int) $profile->id,
                        'ip'            => $this->maskedIp((string) $profile->encrypted_ip),
                        'status'        => $profile->review_status,
                        'hit_count'     => (int) $profile->hit_count,
                        'last_score'    => (int) $profile->last_score,
                        'max_score'     => (int) $profile->max_score,
                        'last_action'   => $profile->last_action,
                        'last_reasons'  => $this->decodeJson($profile->last_reasons),
                        'last_seen_at'  => (string) ($profile->last_seen_at ?? ''),
                        'reviewed_at'   => (string) ($profile->reviewed_at ?? ''),
                        'review_note'   => $profile->review_note,
                    ];
                })->all(),
                'pagination' => [
                    'page'      => $paginator->currentPage(),
                    'per_page'  => $paginator->perPage(),
                    'total'     => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ];
        } catch (\Throwable $exception) {
            Log::warning('CyberCloak 读取风险 IP 审核列表失败', ['exception' => $exception->getMessage()]);

            return ['available' => false, 'items' => [], 'pagination' => ['page' => 1, 'per_page' => $perPage, 'total' => 0, 'last_page' => 1]];
        }
    }

    /**
     * 保存人工审核结论；blocked 会在后续无凭据请求中成为高优先级风险信号。
     */
    public function review(int $profileId, string $status, ?string $note, ?int $adminId): bool
    {
        if (! $this->available() || ! in_array($status, ['unreviewed', 'trusted', 'suspicious', 'blocked'], true)) {
            return false;
        }

        try {
            $profile = DB::table(self::PROFILE_TABLE)->where('id', $profileId)->first();
            if (! $profile) {
                return false;
            }

            DB::table(self::PROFILE_TABLE)->where('id', $profileId)->update([
                'review_status' => $status,
                'review_note'   => $note,
                'reviewed_by'   => $adminId,
                'reviewed_at'   => now(),
                'updated_at'    => now(),
            ]);
            Log::info('CyberCloak 风险 IP 审核状态已更新', [
                'profile_id' => $profileId,
                'status'     => $status,
                'admin_id'   => $adminId,
            ]);

            return true;
        } catch (\Throwable $exception) {
            Log::warning('CyberCloak 更新风险 IP 审核状态失败', ['exception' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * 迁移尚未执行时保持功能降级，避免升级中的前台请求报错。
     */
    private function available(): bool
    {
        try {
            return Schema::hasTable(self::PROFILE_TABLE) && Schema::hasTable(self::EVENT_TABLE);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 为 IP 关联生成不可逆摘要，数据库索引和缓存均不保存明文地址。
     */
    private function ipHash(string $ip): string
    {
        return hash_hmac('sha256', $ip, (string) config('app.key', 'cyber-cloak'));
    }

    /**
     * 只写入固定字段和有限长度的信号，避免可控请求头无限膨胀审计表。
     *
     * @param array<string,mixed> $signals
     * @return array<string,mixed>
     */
    private function sanitizeSignals(array $signals): array
    {
        $allowed = [
            'country', 'asn', 'anonymous', 'cloud_provider', 'network_type', 'cloud_range', 'languages',
            'ip_reputation', 'blocked_asn', 'datacenter_asn', 'cloud_ip_range', 'automation_signal',
            'behavior_window', 'behavior_requests', 'behavior_routes', 'behavior_invalid_cookies',
            'behavior_request_burst', 'behavior_route_scan', 'behavior_invalid_context_cookie', 'behavior_network_activity',
            'manual_risk_status', 'manual_risk_suspicious',
        ];

        return array_filter(
            array_intersect_key($signals, array_flip($allowed)),
            static fn (mixed $value): bool => is_scalar($value) || is_array($value) || $value === null
        );
    }

    /**
     * 路由仅用于审核定位，不包含 query 参数。
     */
    private function routeIdentifier(Request $request): string
    {
        $name = trim((string) $request->route()?->getName());

        return $name !== '' ? $name : $request->method() . ':' . $request->path();
    }

    /**
     * 将数组稳定编码为审计 JSON；编码失败时保存空对象，避免影响前台。
     */
    private function json(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable) {
            return '{}';
        }
    }

    /**
     * 兼容 MySQL 返回的 JSON 字符串和数据库驱动返回的数组。
     *
     * @return array<int|string,mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 读取加密 IP 后只返回脱敏地址，完整地址不出现在审核列表接口。
     */
    private function maskedIp(string $encryptedIp): string
    {
        try {
            $ip = Crypt::decryptString($encryptedIp);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $parts = explode('.', $ip);

                return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.*';
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $parts = explode(':', $ip);

                return implode(':', array_slice($parts, 0, 3)) . ':*';
            }
        } catch (\Throwable) {
            // 密钥轮换后旧记录可能无法解密，仍保留档案和审核状态。
        }

        return '已加密';
    }
}
