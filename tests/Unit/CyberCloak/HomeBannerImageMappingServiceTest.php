<?php

namespace Tests\Unit\CyberCloak;

use Plugin\CyberCloak\Services\HomeBannerImageMappingService;
use Plugin\CyberCloak\Services\StoreContext;
use Tests\TestCase;

class HomeBannerImageMappingServiceTest extends TestCase
{
    /**
     * 展示模式应替换已配置的 Banner 图片，并清空未配置的真实图片源。
     */
    public function test_public_mode_projects_cloak_banner_images_and_clears_unmapped_sources(): void
    {
        $context = new StoreContext;
        $context->activate(['mode' => StoreContext::PUBLIC]);
        $realImage = [
            'src' => ['en' => 'image/catalog/real/banner-en.webp', 'zh_cn' => 'image/catalog/real/banner-zh.webp'],
            'alt' => ['en' => 'Real banner', 'zh_cn' => '真实 Banner'],
        ];
        $publicImage = [
            'src' => ['en' => 'image/catalog/cloak/banner-en.webp', 'zh_cn' => 'image/catalog/cloak/banner-zh.webp'],
            'alt' => ['en' => 'Cloak banner', 'zh_cn' => 'Cloak Banner'],
        ];
        $moduleId = 'homepage-slide';
        $service  = $this->service($context, [
            HomeBannerImageMappingService::sourceKey($moduleId, $realImage) => [
                'id'           => 1,
                'status'       => 'active',
                'public_image' => $publicImage,
            ],
        ]);

        $modules = [[
            'module_id' => $moduleId,
            'code'      => 'slideshow',
            'content'   => [
                'images' => [
                    ['image' => $realImage],
                    ['image' => 'image/catalog/real/unmapped.webp'],
                ],
            ],
        ]];

        $result = $service->mapModules($modules);

        $this->assertSame($publicImage, $result[0]['content']['images'][0]['image']);
        $this->assertSame('', $result[0]['content']['images'][1]['image']);
    }

    /**
     * 真实模式必须保留后台装修保存的图片数据。
     */
    public function test_real_mode_keeps_original_banner_images(): void
    {
        $context = new StoreContext;
        $context->activate(['mode' => StoreContext::REAL]);
        $modules = [[
            'module_id' => 'homepage-banner',
            'code'      => 'img_text_banner',
            'content'   => ['image' => 'image/catalog/real/banner.webp'],
        ]];

        $this->assertSame($modules, $this->service($context, [])->mapModules($modules));
    }

    /**
     * 图片字段顺序或 alt 文案变化不应导致已保存的 Banner 映射身份失效。
     */
    public function test_source_key_is_stable_when_associative_image_fields_reorder(): void
    {
        $first = [
            'src' => ['en' => 'image/catalog/real/en.webp', 'zh_cn' => 'image/catalog/real/zh.webp'],
            'alt' => ['en' => 'Real', 'zh_cn' => '真实'],
        ];
        $second = [
            'alt' => ['zh_cn' => '新文案', 'en' => 'Updated text'],
            'src' => ['zh_cn' => 'image/catalog/real/zh.webp', 'en' => 'image/catalog/real/en.webp'],
        ];

        $this->assertSame(
            HomeBannerImageMappingService::sourceKey('homepage-banner', $first),
            HomeBannerImageMappingService::sourceKey('homepage-banner', $second)
        );
    }

    /**
     * 使用内存映射替代数据库，单元测试只验证图片投影规则。
     *
     * @param array<string,array<string,mixed>> $mappings
     */
    private function service(StoreContext $context, array $mappings): HomeBannerImageMappingService
    {
        return new class($context, $mappings) extends HomeBannerImageMappingService
        {
            /** @var array<string,array<string,mixed>> */
            private array $mappings;

            /**
             * 注入固定映射数据，避免单元测试依赖真实 MySQL。
             *
             * @param array<string,array<string,mixed>> $mappings
             */
            public function __construct(StoreContext $context, array $mappings)
            {
                parent::__construct($context);
                $this->mappings = $mappings;
            }

            /**
             * 测试环境视为迁移已经执行。
             */
            protected function tableExists(): bool
            {
                return true;
            }

            /**
             * 从内存返回请求需要的图片映射。
             *
             * @param array<int,string> $sourceKeys
             * @return array<string,array<string,mixed>>
             */
            protected function mappingRows(array $sourceKeys): array
            {
                return array_intersect_key($this->mappings, array_flip($sourceKeys));
            }
        };
    }
}
