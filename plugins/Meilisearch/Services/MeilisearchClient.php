<?php

namespace Plugin\Meilisearch\Services;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use RuntimeException;

class MeilisearchClient
{
    public function __construct(private ?ClientInterface $http = null)
    {
        $this->http ??= new Client();
    }

    public function health(): array
    {
        return $this->request('GET', '/health');
    }

    /**
     * 分页读取 Meilisearch 中的全部索引。
     *
     * Meilisearch 默认只返回一页，后台索引管理需要看到所有历史版本索引，
     * 因此不能只请求一次 /indexes。
     *
     * @return array<int,array<string,mixed>>
     */
    public function listIndexes(): array
    {
        $indexes = [];
        $offset  = 0;
        $limit   = 1000;

        do {
            $result  = $this->request('GET', '/indexes?limit=' . $limit . '&offset=' . $offset);
            $batch   = is_array($result['results'] ?? null) ? $result['results'] : [];
            $indexes = array_merge($indexes, $batch);
            $offset += count($batch);
            $total = (int) ($result['total'] ?? 0);
        } while ($batch && (($total > 0 && $offset < $total) || ($total === 0 && count($batch) === $limit)));

        return $indexes;
    }

    public function createIndex(string $index): int
    {
        $response = $this->request('POST', '/indexes', [
            'json' => ['uid' => $index, 'primaryKey' => 'id'],
        ]);

        return (int) ($response['taskUid'] ?? 0);
    }

    public function configureIndex(string $index): int
    {
        // 顺序即权重：商品名称最高，分类名称最低，避免分类词把整个分类的商品顶到名称精确匹配之前。
        $searchable = ['name', 'sku', 'model'];
        if (Settings::categorySearchEnabled()) {
            $searchable[] = 'category_names';
        }

        $response = $this->request('PATCH', '/indexes/' . rawurlencode($index) . '/settings', [
            'json' => [
                'searchableAttributes' => $searchable,
                'filterableAttributes' => ['active', 'visible', 'category_ids', 'brand_id', 'price', 'sku_exact'],
                'sortableAttributes'   => ['price', 'position', 'sales', 'views', 'created_at', 'updated_at', 'name'],
                // 只返回商品 ID，商品数据一律回数据库读取，索引不对外暴露 SKU 和名称内容。
                'displayedAttributes'  => ['id'],
                // SKU 和型号必须精确匹配：容错会让 AB12345 命中 AB12346，客户据此下单就会拿错货。
                'typoTolerance'        => [
                    'enabled'             => true,
                    'disableOnAttributes' => ['sku', 'model', 'sku_exact'],
                    'minWordSizeForTypos' => ['oneTypo' => 5, 'twoTypos' => 9],
                ],
                'pagination'           => ['maxTotalHits' => 100000],
            ],
        ]);

        return (int) ($response['taskUid'] ?? 0);
    }

    public function addDocuments(string $index, array $documents): int
    {
        if (! $documents) {
            return 0;
        }

        $response = $this->request('POST', '/indexes/' . rawurlencode($index) . '/documents?primaryKey=id', [
            'json' => array_values($documents),
        ]);

        return (int) ($response['taskUid'] ?? 0);
    }

    public function deleteDocument(string $index, int $productId): int
    {
        $response = $this->request('DELETE', '/indexes/' . rawurlencode($index) . '/documents/' . $productId);

        return (int) ($response['taskUid'] ?? 0);
    }

    public function deleteIndex(string $index): int
    {
        $response = $this->request('DELETE', '/indexes/' . rawurlencode($index));

        return (int) ($response['taskUid'] ?? 0);
    }

    /**
     * 读取索引统计，供后台展示已索引文档数并与数据库商品数比对。
     */
    public function indexStats(string $index): array
    {
        return $this->request('GET', '/indexes/' . rawurlencode($index) . '/stats');
    }

    public function search(string $index, string $query, int $page, int $perPage, array $options = []): array
    {
        // 商品目录中 Jersey、shirt 等词频很高；frequency 优先保留稀有的品牌/分类词，
        // 避免默认 last 从查询末尾丢掉更有区分度的关键词。
        if ($query !== '' && ! array_key_exists('matchingStrategy', $options)) {
            $options['matchingStrategy'] = 'frequency';
        }

        return $this->request('POST', '/indexes/' . rawurlencode($index) . '/search', [
            'json' => array_merge([
                'q'                    => $query,
                'filter'               => 'visible = true',
                'page'                 => $page,
                'hitsPerPage'          => $perPage,
                'attributesToRetrieve' => ['id'],
            ], $options),
        ]);
    }

    public function waitForTask(int $taskUid, int $timeoutSeconds = 120): array
    {
        if ($taskUid <= 0) {
            return [];
        }

        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $task   = $this->request('GET', '/tasks/' . $taskUid);
            $status = $task['status'] ?? '';
            if ($status === 'succeeded') {
                return $task;
            }
            if (in_array($status, ['failed', 'canceled'], true)) {
                throw new RuntimeException('Meilisearch 任务失败：' . ($task['error']['message'] ?? $status));
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException("等待 Meilisearch 任务 {$taskUid} 超时");
    }

    private function request(string $method, string $path, array $options = []): array
    {
        $headers = ['Accept' => 'application/json'];
        if (Settings::apiKey() !== '') {
            $headers['Authorization'] = 'Bearer ' . Settings::apiKey();
        }

        $response = $this->http->request($method, Settings::host() . $path, array_merge([
            'headers'         => $headers,
            'timeout'         => Settings::timeout(),
            'connect_timeout' => min(Settings::timeout(), 1),
            'http_errors'     => false,
        ], $options));

        $body = (string) $response->getBody();
        $data = $body === '' ? [] : json_decode($body, true);
        if (! is_array($data)) {
            throw new RuntimeException('Meilisearch 返回了无法解析的响应');
        }

        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException('Meilisearch 请求失败：' . ($data['message'] ?? $response->getReasonPhrase()));
        }

        return $data;
    }
}
