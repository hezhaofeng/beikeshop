<?php

namespace Plugin\CyberCloak\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CatalogResolver
{
    public function __construct(
        private readonly AccessKeyService $accessKeys,
        private readonly ContextTicketService $tickets,
        private readonly IpAccessService $ipAccess,
        private readonly ?StoreContext $context = null,
        private readonly ?TrafficFunnelService $trafficFunnel = null,
    ) {
    }

    /**
     * 解析请求模式，并移除 query 中的 key，防止敏感参数进入分页和站内链接。
     *
     * @return array{mode: string, key_id: string|null, client_ip: string, reason: string, risk: array<string,mixed>|null, cookie: array<string, mixed>}
     */
    public function resolve(Request $request): array
    {
        $ipResult     = $this->ipAccess->evaluate($request);
        $keyParameter = (string) config('cyber_cloak.key_parameter', 'key');
        $queryValue   = $request->query($keyParameter, '');
        $queryKey     = is_scalar($queryValue) ? trim((string) $queryValue) : '';
        if ($keyParameter !== '') {
            // 只修改 Query 参数，不触碰 POST 数据中的同名字段。
            $request->query->remove($keyParameter);
        }

        if (! $ipResult['allowed']) {
            $cookieValue = $request->cookie((string) config('cyber_cloak.cookie_name', 'beike_context'));

            return $this->publicResolution(
                $ipResult,
                is_string($cookieValue),
                $ipResult['provider_unavailable']
                    ? 'ip_provider_unavailable'
                    : ($ipResult['blacklisted'] ? 'ip_blacklist' : 'ip_not_whitelisted')
            );
        }

        $cookieName  = (string) config('cyber_cloak.cookie_name', 'beike_context');
        $cookieValue = $request->cookie($cookieName);

        if ($queryKey !== '') {
            $record = $this->accessKeys->findValid($queryKey);
            if ($record) {
                // 有效 key 是真实站显式入口，优先于漏斗国家、语言和 UA 信号。
                return $this->realResolution($record, $ipResult['ip'], true, 'query_key');
            }
        }

        $ticket      = $this->tickets->verify(is_string($cookieValue) ? $cookieValue : null);
        if ($ticket) {
            // 已验证的签名 Cookie 代表之前完成过 key 校验，同样优先于漏斗信号。
            return $this->realResolution(
                $ticket['record'],
                $ipResult['ip'],
                $this->tickets->shouldRefresh($ticket),
                'cookie'
            );
        }

        $funnel = $this->trafficFunnel?->evaluate($request, $ipResult);
        if ($funnel && in_array($funnel['action'], ['block', 'challenge', 'rate_limit'], true)) {
            return $this->publicResolution($ipResult, is_string($cookieValue), 'traffic_' . $funnel['action'], $funnel);
        }

        return $this->publicResolution(
            $ipResult,
            is_string($cookieValue),
            $queryKey !== '' ? 'invalid_key' : 'no_credentials',
            $funnel
        );
    }

    /**
     * 获取当前请求或指定模式对应的商品库连接名。
     */
    public function connectionName(string $mode = null): string
    {
        $context = $this->context ?: app(StoreContext::class);
        // 解析器本身保留原有 public 默认值；真正的模型连接切换由激活态控制。
        $mode = $mode ?: ($context?->mode() ?? StoreContext::PUBLIC);
        if (! in_array($mode, [StoreContext::REAL, StoreContext::PUBLIC], true)) {
            throw new \InvalidArgumentException('商品库模式无效');
        }

        return (string) config("cyber_cloak.connections.{$mode}", $mode === StoreContext::REAL ? 'mysql' : 'catalog_public');
    }

    /**
     * 创建显式指定商品库的查询构造器，避免关系查询误用默认连接。
     */
    public function table(string $table, string $mode = null): Builder
    {
        if (! in_array($table, ['products', 'product_descriptions', 'product_skus', 'product_categories', 'categories', 'category_descriptions', 'brands'], true)) {
            throw new \InvalidArgumentException('商品库表名不在允许范围内');
        }

        return DB::connection($this->connectionName($mode))->table($table);
    }

    /**
     * 按商品 ID 从当前商品库读取商品，供后续商品仓储和路由绑定复用。
     */
    public function findProduct(int $id, string $mode = null): ?object
    {
        return $this->table('products', $mode)
            ->where('id', $id)
            ->where('active', 1)
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * 按 SKU ID 从当前商品库读取 SKU，并限制 SKU 必须属于启用商品。
     */
    public function findSku(int $id, string $mode = null): ?object
    {
        return $this->table('product_skus', $mode)
            ->where('product_skus.id', $id)
            ->where('product_skus.active', 1)
            ->join('products', 'products.id', '=', 'product_skus.product_id')
            ->where('products.active', 1)
            ->whereNull('products.deleted_at')
            ->select('product_skus.*')
            ->first();
    }

    /**
     * 构造真实模式结果，并在 query key 或票据接近过期时续签 Cookie。
     */
    private function realResolution(array $record, string $ip, bool $issueCookie, string $reason): array
    {
        $result = [
            'mode'       => StoreContext::REAL,
            'key_id'     => $record['id'],
            'client_ip'  => $ip,
            'reason'     => $reason,
            'risk'       => ['action' => 'allow', 'stage' => 'access_key', 'score' => 0, 'reasons' => ['valid_access_key']],
            'cookie'     => ['action' => 'none'],
        ];

        if ($issueCookie) {
            $expiresAt        = $this->accessKeys->expiresAtTimestamp($record);
            $result['cookie'] = [
                'action'  => 'set',
                'value'   => $this->tickets->issue($record),
                'minutes' => $this->cookieLifetime($expiresAt),
            ];
        }

        return $result;
    }

    /**
     * 构造展示模式结果，必要时清除失效或被黑名单阻断的 Cookie。
     */
    private function publicResolution(array $ipResult, bool $hasCookie, string $reason, array $risk = null): array
    {
        return [
            'mode'      => StoreContext::PUBLIC,
            'key_id'    => null,
            'client_ip' => $ipResult['ip'],
            'reason'    => $reason,
            'risk'      => $risk,
            'cookie'    => ['action' => $hasCookie ? 'forget' : 'none'],
        ];
    }

    /**
     * 让 Cookie 的浏览器有效期不超过服务端 key 的有效期。
     */
    private function cookieLifetime(int $keyExpiresAt): int
    {
        $configured = max(0, (int) config('cyber_cloak.cookie_lifetime_minutes', 43200));
        if ($keyExpiresAt === 0) {
            return $configured;
        }

        $remaining = max(1, (int) ceil(($keyExpiresAt - time()) / 60));

        return $configured === 0 ? $remaining : min($configured, $remaining);
    }
}
