<?php

namespace Plugin\CyberCloak\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 维护无凭据访问的短期行为画像。
 *
 * 仅保存 HMAC 后的 IP 和路由摘要到缓存，不依赖第三方识别服务；
 * 新型自动化流量会因高频、快速遍历路由或重复失效票据而进入人工审核。
 */
class TrafficBehaviorService
{
    /**
     * 记录当前请求的窗口行为，并返回可解释的行为风险信号。
     *
     * @param array{enabled:bool,window:int,max_requests:int,max_routes:int,invalid_cookie_max:int,score:int,cookie_name:string} $policy
     * @param array{asn:?int,risk_asn:bool,anonymous:bool,datacenter:bool,cloud_provider:?string,network_type:string} $networkContext
     * @return array{score:int,reasons:array<int,string>,scores:array<string,int>,signals:array<string,mixed>}
     */
    public function observe(Request $request, string $ip, array $policy, array $networkContext = []): array
    {
        $result = ['score' => 0, 'reasons' => [], 'scores' => [], 'signals' => []];
        if (! $policy['enabled'] || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return $result;
        }

        $window  = max(1, $policy['window']);
        $expires = now()->addSeconds($window);
        $prefix  = 'cyber-cloak:behavior:' . $this->ipHash($ip) . ':' . intdiv(time(), $window);
        $route   = $this->routeIdentifier($request);

        try {
            $requests = $this->increment("{$prefix}:requests", $expires);
            $routeKey = "{$prefix}:route:" . sha1($route);
            $routes   = $this->counter("{$prefix}:routes", $expires);
            if (Cache::add($routeKey, 1, $expires)) {
                $routes = $this->increment("{$prefix}:routes", $expires);
            }

            $invalidCookies = 0;
            if ($policy['cookie_name'] !== '' && $request->hasCookie($policy['cookie_name'])) {
                $invalidCookies = $this->increment("{$prefix}:invalid-cookie", $expires);
            }

            $result['signals']['behavior_window'] = $window;
            $result['signals']['behavior_requests'] = $requests;
            $result['signals']['behavior_routes'] = $routes;
            $result['signals']['behavior_invalid_cookies'] = $invalidCookies;

            if ($requests > max(1, $policy['max_requests'])) {
                $this->addSignal($result, $policy['score'], 'behavior_request_burst', [
                    'requests' => $requests,
                    'limit'    => max(1, $policy['max_requests']),
                    'window'   => $window,
                ]);
            }
            if ($routes > max(1, $policy['max_routes'])) {
                $this->addSignal($result, $policy['score'], 'behavior_route_scan', [
                    'routes' => $routes,
                    'limit'  => max(1, $policy['max_routes']),
                    'window' => $window,
                ]);
            }
            if ($invalidCookies >= max(1, $policy['invalid_cookie_max'])) {
                $this->addSignal($result, max(1, intdiv($policy['score'], 2)), 'behavior_invalid_context_cookie', [
                    'count' => $invalidCookies,
                    'limit' => max(1, $policy['invalid_cookie_max']),
                    'window' => $window,
                ]);
            }

            // ASN、匿名 IP 和云网段只在已出现行为异常时参与组合判断，避免网络类型本身导致误杀。
            $this->addNetworkActivitySignal($result, $policy['score'], $networkContext);
        } catch (\Throwable $exception) {
            // 缓存暂不可用时不影响前台展示，只保留可审计的降级标记。
            Log::warning('CyberCloak 行为画像缓存不可用，已跳过本次行为识别', [
                'exception' => $exception->getMessage(),
            ]);
            $result['signals']['behavior_cache'] = 'unavailable';
        }

        return $result;
    }

    /**
     * 初始化并递增窗口计数器，保证首次写入也带有过期时间。
     */
    private function increment(string $key, \DateTimeInterface $expires): int
    {
        Cache::add($key, 0, $expires);

        return (int) Cache::increment($key);
    }

    /**
     * 读取已有计数器，路由首次出现时由 increment 初始化。
     */
    private function counter(string $key, \DateTimeInterface $expires): int
    {
        Cache::add($key, 0, $expires);

        return (int) Cache::get($key, 0);
    }

    /**
     * 构造稳定的路由标识，不保存 query 参数或请求正文。
     */
    private function routeIdentifier(Request $request): string
    {
        $name = trim((string) $request->route()?->getName());

        return $name !== '' ? $name : $request->method() . ':' . $request->path();
    }

    /**
     * 仅用密钥派生的 IP 摘要作为缓存键，避免缓存键保存明文地址。
     */
    private function ipHash(string $ip): string
    {
        return hash_hmac('sha256', $ip, (string) config('app.key', 'cyber-cloak'));
    }

    /**
     * 将网络情报附加到已命中的行为模式中，形成可审核的组合信号。
     *
     * 普通 ASN 只作为审计上下文；匿名、数据中心或后台指定的风险 ASN 才会增强行为风险分。
     *
     * @param array{score:int,reasons:array<int,string>,scores:array<string,int>,signals:array<string,mixed>} $result
     * @param array{asn?:?int,risk_asn?:bool,anonymous?:bool,datacenter?:bool,cloud_provider?:?string,network_type?:string} $networkContext
     */
    private function addNetworkActivitySignal(array &$result, int $score, array $networkContext): void
    {
        $behaviorReasons = array_values(array_intersect($result['reasons'], [
            'behavior_request_burst',
            'behavior_route_scan',
            'behavior_invalid_context_cookie',
        ]));
        if ($behaviorReasons === []) {
            return;
        }

        $contexts = [];
        $asn = (int) ($networkContext['asn'] ?? 0);
        if ($asn > 0) {
            $contexts['asn'] = $asn;
        }
        if (($networkContext['anonymous'] ?? false) === true) {
            $contexts['anonymous_ip'] = true;
        }
        if (($networkContext['datacenter'] ?? false) === true) {
            $contexts['datacenter'] = [
                'provider' => $networkContext['cloud_provider'] ?? 'DATACENTER',
                'type'     => $networkContext['network_type'] ?? 'DATACENTER',
            ];
        }
        if (($networkContext['risk_asn'] ?? false) === true) {
            $contexts['risk_asn'] = $asn > 0 ? $asn : true;
        }

        // 普通运营商 ASN 仅用于审计，不单独把行为异常升级为网络组合风险。
        if (! isset($contexts['anonymous_ip']) && ! isset($contexts['datacenter']) && ! isset($contexts['risk_asn'])) {
            return;
        }

        $this->addSignal($result, max(1, intdiv($score, 2)), 'behavior_network_activity', [
            'behavior_reasons' => $behaviorReasons,
            'network_context'  => $contexts,
        ]);
    }

    /**
     * 将行为分数限制在 0 到 100，并保留对应的原始信号。
     */
    private function addSignal(array &$result, int $score, string $reason, array $value): void
    {
        $result['score']            = min(100, $result['score'] + max(0, $score));
        $result['reasons'][]        = $reason;
        $result['scores'][$reason]  = max(0, $score);
        $result['signals'][$reason] = $value;
    }
}
