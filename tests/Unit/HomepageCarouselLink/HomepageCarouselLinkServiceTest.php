<?php

namespace Tests\Unit\HomepageCarouselLink;

use PHPUnit\Framework\TestCase;
use Plugin\HomepageCarouselLink\Services\HomepageCarouselLinkService;

class HomepageCarouselLinkServiceTest extends TestCase
{
    public function test_normalize_slides_keeps_module_index_and_window_flag(): void
    {
        $service = new HomepageCarouselLinkService;

        $result = $service->normalizeSlides([
            'home-top' => [
                0 => ['type' => 'category', 'value' => ['id' => 12], 'new_window' => '1'],
                1 => ['type' => 'custom', 'value' => 'example.com', 'new_window' => 0],
                2 => 'skip',
            ],
        ]);

        $this->assertSame('category', $result['home-top'][0]['type']);
        $this->assertSame('12', $result['home-top'][0]['value']);
        $this->assertTrue($result['home-top'][0]['new_window']);
        $this->assertSame('custom', $result['home-top'][1]['type']);
        $this->assertSame('example.com', $result['home-top'][1]['value']);
        $this->assertFalse($result['home-top'][1]['new_window']);
    }

    public function test_editor_modules_keep_slide_order_and_merge_saved_custom_links(): void
    {
        $service = new HomepageCarouselLinkService;

        $modules = [
            [
                'code'      => 'slideshow',
                'module_id' => 'home-top',
                'content'   => [
                    'images' => [
                        [
                            'image' => ['src' => 'image/catalog/demo/banner-1.jpg'],
                            'title' => ['zh_cn' => '首图'],
                            'link'  => ['type' => 'custom', 'value' => 'https://design.example.com', 'new_window' => false],
                        ],
                        [
                            'image' => ['src' => 'image/catalog/demo/banner-2.jpg'],
                            'title' => ['zh_cn' => '次图'],
                            'link'  => ['type' => 'custom', 'value' => 'https://fallback.example.com', 'new_window' => false],
                        ],
                    ],
                ],
            ],
        ];

        $result = $service->editorModules($modules, [
            'home-top' => [
                0 => ['type' => 'custom', 'value' => 'https://plugin.example.com', 'new_window' => true],
            ],
        ]);

        $this->assertSame('slideshow', $result[0]['code']);
        $this->assertSame('首图', $result[0]['images'][0]['title']);
        $this->assertSame('custom', $result[0]['images'][0]['link']['type']);
        $this->assertSame('https://plugin.example.com', $result[0]['images'][0]['link']['value']);
        $this->assertTrue($result[0]['images'][0]['link']['new_window']);
        $this->assertSame('https://fallback.example.com', $result[0]['images'][1]['link']['value']);
    }

    public function test_apply_configured_links_overrides_runtime_links(): void
    {
        $service = new HomepageCarouselLinkService;

        $content = [
            'images' => [
                [
                    'image' => 'image/catalog/demo/banner-1.jpg',
                    'link'  => ['type' => 'custom', 'value' => 'https://design.example.com', 'new_window' => false, 'link' => 'https://design.example.com'],
                ],
                [
                    'image' => 'image/catalog/demo/banner-2.jpg',
                    'link'  => ['type' => 'custom', 'value' => 'https://design.example.com/2', 'new_window' => false, 'link' => 'https://design.example.com/2'],
                ],
            ],
        ];

        $result = $service->applyConfiguredLinksToContent('home-top', $content, [
            'home-top' => [
                0 => ['type' => 'custom', 'value' => '/promo', 'new_window' => true],
            ],
        ]);

        $this->assertSame('/promo', $result['images'][0]['link']['value']);
        $this->assertTrue($result['images'][0]['link']['new_window']);
        $this->assertSame('https://design.example.com/2', $result['images'][1]['link']['value']);
    }

    public function test_link_type_options_include_category_and_custom(): void
    {
        $service = new HomepageCarouselLinkService;

        $options = $service->linkTypeOptions();
        $this->assertSame(['category', 'custom'], array_column($options, 'value'));
    }

    public function test_custom_url_keeps_absolute_and_relative_paths(): void
    {
        $service = new HomepageCarouselLinkService;

        $this->assertSame('https://example.com', $this->invoke($service, 'customUrl', ['https://example.com']));
        $this->assertSame('/promo', $this->invoke($service, 'customUrl', ['/promo']));
        $this->assertSame('//example.com', $this->invoke($service, 'customUrl', ['example.com']));
    }

    private function invoke(object $object, string $method, array $arguments = []): mixed
    {
        $ref = new \ReflectionMethod($object, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($object, $arguments);
    }
}
