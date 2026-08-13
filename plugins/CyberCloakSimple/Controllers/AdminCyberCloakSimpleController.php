<?php

namespace Plugin\CyberCloakSimple\Controllers;

use Beike\Admin\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Plugin\CyberCloakSimple\Services\MappingService;

/**
 * CyberCloak Simple 后台的一键映射接口。
 */
class AdminCyberCloakSimpleController extends Controller
{
    /**
     * 一键补齐商品映射，并保留管理员已经配置的展示目标。
     */
    public function rebuildProductMappings(MappingService $mappings): mixed
    {
        return $this->rebuild($mappings, 'product');
    }

    /**
     * 一键补齐分类映射，并让头部分类导航立即复用新映射。
     */
    public function rebuildCategoryMappings(MappingService $mappings): mixed
    {
        return $this->rebuild($mappings, 'category');
    }

    /**
     * 执行统一的映射生成、日志记录和 JSON 响应。
     */
    private function rebuild(MappingService $mappings, string $type): mixed
    {
        try {
            $result = $mappings->autoMap($type);
            Log::info('CyberCloak Simple 后台一键处理映射', [
                'type'     => $type,
                'added'    => $result['added'],
                'total'    => $result['total'],
                'admin_id' => auth()->id(),
            ]);

            return json_success('映射已处理', $result);
        } catch (\Throwable $exception) {
            Log::warning('CyberCloak Simple 映射处理失败', [
                'type'  => $type,
                'error' => $exception->getMessage(),
            ]);

            return json_fail('映射处理失败：' . $exception->getMessage());
        }
    }
}
