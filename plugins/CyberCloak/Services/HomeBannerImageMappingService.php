<?php

namespace Plugin\CyberCloak\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 管理首页 Banner 图片的真实库到 Cloak 展示库投影。
 *
 * 首页布局和真实装修配置保持唯一，展示模式只替换 content 中的 image 字段，
 * 因此幻灯片排序、商品模块和页面模板仍由同一份配置驱动。
 */
class HomeBannerImageMappingService
{
    private const TABLE = 'catalog_home_banner_mappings';

    /**
     * 注入请求级商品上下文，后台扫描时不依赖当前前台模式。
     */
    public function __construct(private readonly StoreContext $context)
    {
    }

    /**
     * 在展示模式中替换首页模块里的 Banner 图片；真实模式保持原始装修数据。
     *
     * 未配置的展示图片清空源图片，避免把真实站点素材泄漏到 Cloak 首页。
     * 迁移尚未执行时保留旧版原图，方便分阶段部署。
     *
     * @param array<int,array<string,mixed>> $modules
     * @return array<int,array<string,mixed>>
     */
    public function mapModules(array $modules): array
    {
        if (! $this->isPublicRequest() || ! $this->tableExists()) {
            return $modules;
        }

        $entries = $this->collectEntries($modules);
        if ($entries === []) {
            return $modules;
        }

        $mappings = $this->mappingRows(array_values(array_unique(array_column($entries, 'source_key'))));
        foreach ($entries as $entry) {
            $record = $mappings[$entry['source_key']] ?? null;
            $image  = is_array($record) && ($record['status'] ?? '') === 'active' && ! empty($record['public_image'])
                ? $record['public_image']
                : $this->blankImage($entry['real_image']);

            $moduleIndex                      = $entry['module_index'];
            $modules[$moduleIndex]['content'] = $this->setNestedValue(
                $modules[$moduleIndex]['content'],
                $entry['segments'],
                $image
            );
        }

        return $modules;
    }

    /**
     * 扫描首页装修并返回图片映射状态，扫描本身不写入数据库。
     *
     * @return array<string,mixed>
     */
    public function scan(): array
    {
        $modules  = $this->designModules();
        $entries  = $this->collectEntries($modules);
        $mappings = $this->mappingRows(array_values(array_unique(array_column($entries, 'source_key'))));
        $items    = [];

        foreach ($entries as $entry) {
            $record      = $mappings[$entry['source_key']] ?? [];
            $publicImage = $record['public_image']         ?? null;
            $configured  = $publicImage !== null && $publicImage !== '';
            $status      = $configured ? (string) ($record['status'] ?? 'active') : 'pending';
            $items[]     = [
                'id'             => (int) ($record['id'] ?? 0),
                'module_id'      => $entry['module_id'],
                'module_code'    => $entry['module_code'],
                'path'           => implode('.', $entry['segments']),
                'source_key'     => $entry['source_key'],
                'real_image'     => $entry['real_image'],
                'public_image'   => $publicImage,
                'public_input'   => $this->publicInput($publicImage),
                'real_preview'   => $this->previewUrl($entry['real_image']),
                'public_preview' => $this->previewUrl($publicImage),
                'status'         => $status,
                'configured'     => $configured,
            ];
        }

        $configured = count(array_filter($items, static fn (array $item): bool => $item['configured'] && $item['status'] === 'active'));
        $disabled   = count(array_filter($items, static fn (array $item): bool => $item['status'] === 'disabled'));

        return [
            'module_count' => count($modules),
            'image_count'  => count($items),
            'configured'   => $configured,
            'pending'      => count($items) - $configured - $disabled,
            'disabled'     => $disabled,
            'items'        => $items,
            'scanned_at'   => now()->toDateTimeString(),
        ];
    }

    /**
     * 把当前首页装修中的新图片登记到映射表，保留已有 Cloak 图片目标。
     *
     * @return array<string,mixed>
     */
    public function sync(): array
    {
        if (! $this->tableExists()) {
            throw new \RuntimeException('首页 Banner 图片映射表尚未迁移');
        }

        $entries = $this->collectEntries($this->designModules());
        $now     = now();
        foreach ($entries as $entry) {
            $values = [
                'module_id'   => $entry['module_id'],
                'module_code' => $entry['module_code'],
                'source_path' => implode('.', $entry['segments']),
                'real_image'  => $this->encodeJson($entry['real_image']),
                'updated_at'  => $now,
            ];
            $existing = DB::table(self::TABLE)->where('source_key', $entry['source_key'])->first();
            if ($existing) {
                DB::table(self::TABLE)->where('id', $existing->id)->update($values);

                continue;
            }

            DB::table(self::TABLE)->insert($values + [
                'source_key'   => $entry['source_key'],
                'public_image' => null,
                'status'       => 'pending',
                'created_at'   => $now,
            ]);
        }

        return $this->scan();
    }

    /**
     * 保存一条 Cloak 图片目标；普通图片路径会自动套用真实图片的语言/alt 结构。
     */
    public function update(int $id, mixed $publicImage, string $status = 'active'): array
    {
        if (! $this->tableExists()) {
            throw new \RuntimeException('首页 Banner 图片映射表尚未迁移');
        }

        $row = DB::table(self::TABLE)->where('id', $id)->first();
        if (! $row) {
            throw new \InvalidArgumentException('首页 Banner 图片映射记录不存在');
        }

        $realImage = $this->decodeJson($row->real_image);
        $target    = $this->normalizeSubmittedImage($publicImage);
        $target    = $target === null ? null : $this->mergeTarget($realImage, $target);
        $status    = $target === null ? 'pending' : (in_array($status, ['active', 'disabled'], true) ? $status : 'active');

        DB::table(self::TABLE)->where('id', $id)->update([
            'public_image' => $target === null ? null : $this->encodeJson($target),
            'status'       => $status,
            'updated_at'   => now(),
        ]);

        return $this->scan();
    }

    /**
     * 暴露稳定键算法，便于测试和外部导入工具复用相同的映射身份。
     */
    public static function sourceKey(string $moduleId, mixed $image): string
    {
        return hash('sha256', $moduleId . "\n" . self::encodeCanonical(self::imageSource($image)));
    }

    /**
     * 返回当前首页装修模块。
     *
     * @return array<int,array<string,mixed>>
     */
    private function designModules(): array
    {
        $setting = system_setting('base.design_setting', ['modules' => []]);

        return is_array($setting) && is_array($setting['modules'] ?? null) ? $setting['modules'] : [];
    }

    /**
     * 递归收集模块里的 image 字段，兼容幻灯片、图文 Banner、图标和后续模块。
     *
     * @param array<int,array<string,mixed>> $modules
     * @return array<int,array<string,mixed>>
     */
    private function collectEntries(array $modules): array
    {
        $entries = [];
        foreach ($modules as $moduleIndex => $module) {
            if (! is_array($module) || trim((string) ($module['module_id'] ?? '')) === '') {
                continue;
            }

            $this->collectImageFields(
                $module['content'] ?? [],
                [],
                (int) $moduleIndex,
                (string) $module['module_id'],
                (string) ($module['code'] ?? ''),
                $entries
            );
        }

        return $entries;
    }

    /**
     * 递归读取图片字段并记录其在模块内容中的路径。
     *
     * @param array<int|string,mixed>        $value
     * @param array<int,string>              $segments
     * @param array<int,array<string,mixed>> $entries
     */
    private function collectImageFields(mixed $value, array $segments, int $moduleIndex, string $moduleId, string $moduleCode, array &$entries): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            $path = array_merge($segments, [(string) $key]);
            if ((string) $key === 'image' && $this->isImageValue($child)) {
                $entries[] = [
                    'module_index' => $moduleIndex,
                    'module_id'    => $moduleId,
                    'module_code'  => $moduleCode,
                    'segments'     => $path,
                    'real_image'   => $child,
                    'source_key'   => self::sourceKey($moduleId, $child),
                ];

                continue;
            }

            $this->collectImageFields($child, $path, $moduleIndex, $moduleId, $moduleCode, $entries);
        }
    }

    /**
     * 判断装修器字段是否包含可替换的图片源。
     */
    private function isImageValue(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (! is_array($value)) {
            return false;
        }

        if (array_key_exists('src', $value)) {
            return $this->isImageValue($value['src']);
        }

        foreach ($value as $child) {
            if (is_string($child) && trim($child) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * 批量读取当前首页图片映射，避免每个 Banner 单独查询数据库。
     *
     * @param array<int,string> $sourceKeys
     * @return array<string,array<string,mixed>>
     */
    protected function mappingRows(array $sourceKeys): array
    {
        if ($sourceKeys === [] || ! $this->tableExists()) {
            return [];
        }

        $rows   = DB::table(self::TABLE)->whereIn('source_key', $sourceKeys)->get();
        $result = [];
        foreach ($rows as $row) {
            $publicImage                       = $row->public_image === null ? null : $this->decodeJson($row->public_image);
            $result[(string) $row->source_key] = [
                'id'           => (int) $row->id,
                'status'       => (string) $row->status,
                'public_image' => $publicImage,
            ];
        }

        return $result;
    }

    /**
     * 判断当前请求是否为已激活的 Cloak 展示上下文。
     */
    private function isPublicRequest(): bool
    {
        return $this->context->isActive() && $this->context->isPublic();
    }

    /**
     * 判断映射迁移是否已执行，兼容插件分阶段发布。
     */
    protected function tableExists(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 将嵌套数组中的目标路径替换为展示图片。
     *
     * @param array<int|string,mixed> $value
     * @param array<int,string>       $segments
     * @return array<int|string,mixed>
     */
    private function setNestedValue(array $value, array $segments, mixed $replacement): array
    {
        if ($segments === []) {
            return $value;
        }

        $segment = array_shift($segments);
        if ($segments === []) {
            $value[$segment] = $replacement;

            return $value;
        }

        if (! is_array($value[$segment] ?? null)) {
            $value[$segment] = [];
        }
        $value[$segment] = $this->setNestedValue($value[$segment], $segments, $replacement);

        return $value;
    }

    /**
     * 未配置映射时清空图片源，但保留 src/alt 结构，避免模板出现数组类型错误。
     */
    private function blankImage(mixed $image): mixed
    {
        if (is_string($image)) {
            return '';
        }
        if (! is_array($image)) {
            return '';
        }
        if (array_key_exists('src', $image)) {
            $image['src'] = $this->replaceStrings($image['src'], '');

            return $image;
        }

        return $this->replaceStrings($image, '');
    }

    /**
     * 把普通 Cloak 图片路径套入真实图片的语言和 alt 数据结构。
     */
    private function mergeTarget(mixed $realImage, mixed $target): mixed
    {
        if (is_string($realImage) && is_array($target)) {
            // 单字符串装修字段只能接收图片路径，复杂 JSON 取其中第一个 src 路径。
            return $this->firstString($target);
        }
        if (! is_string($target) || ! is_array($realImage)) {
            return $target;
        }
        if (array_key_exists('src', $realImage)) {
            $realImage['src'] = $this->replaceStrings($realImage['src'], $target);

            return $realImage;
        }

        return $this->replaceStrings($realImage, $target);
    }

    /**
     * 递归替换图片源中的字符串叶子，保留语言键和图片 alt 字段。
     */
    private function replaceStrings(mixed $value, string $replacement): mixed
    {
        if (is_string($value)) {
            return $replacement;
        }
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $child) {
            $value[$key] = $this->replaceStrings($child, $replacement);
        }

        return $value;
    }

    /**
     * 将提交的纯路径或 JSON 图片结构标准化。
     */
    private function normalizeSubmittedImage(mixed $value): mixed
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);
        if (! in_array($value[0] ?? '', ['{', '['], true)) {
            return $value;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Cloak 图片 JSON 格式不正确');
        }

        return $decoded;
    }

    /**
     * 统一读取展示图片输入框内容；复杂多语言结构使用 JSON 展示。
     */
    private function publicInput(mixed $image): string
    {
        if ($image === null || $image === '') {
            return '';
        }
        if (is_string($image)) {
            return $image;
        }

        return $this->encodeJson($image);
    }

    /**
     * 提取图片预览地址，兼容字符串、src 对象和多语言图片结构。
     */
    private function previewUrl(mixed $image): string
    {
        $path = $this->firstString($image);
        if ($path === '') {
            return '';
        }

        return function_exists('image_origin') ? (string) image_origin($path) : $path;
    }

    /**
     * 深度读取图片结构中的第一个字符串路径。
     */
    private function firstString(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (! is_array($value)) {
            return '';
        }
        if (array_key_exists('src', $value)) {
            return $this->firstString($value['src']);
        }
        foreach ($value as $child) {
            $path = $this->firstString($child);
            if ($path !== '') {
                return $path;
            }
        }

        return '';
    }

    /**
     * 解析数据库中的 JSON 图片字段；历史脏数据回退为原始字符串。
     */
    private function decodeJson(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    /**
     * 以统一 JSON 格式保存图片结构。
     */
    private function encodeJson(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * 对关联数组排序后计算稳定 JSON，避免字段顺序变化导致映射身份漂移。
     */
    private static function encodeCanonical(mixed $value): string
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as $key => $child) {
                $value[$key] = self::canonicalize($child);
            }
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * 递归规范化图片数据中的关联数组键顺序。
     */
    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $child) {
            $value[$key] = self::canonicalize($child);
        }

        return $value;
    }

    /**
     * 映射身份只使用图片 src，修改 alt 文案不会使已保存的 Cloak 图片映射失效。
     */
    private static function imageSource(mixed $image): mixed
    {
        if (is_array($image) && array_key_exists('src', $image)) {
            return self::imageSource($image['src']);
        }

        return $image;
    }
}
