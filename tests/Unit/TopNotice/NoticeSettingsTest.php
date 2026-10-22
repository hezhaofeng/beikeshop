<?php

namespace Tests\Unit\TopNotice;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;
use Plugin\TopNotice\Services\NoticeSettings;

class NoticeSettingsTest extends TestCase
{
    private function resolve(array $settings = [], bool $isHome = true, string $time = '2026-10-01 12:00:00'): ?array
    {
        return NoticeSettings::resolve(array_replace([
            'status'           => 1,
            'fallback_content' => 'Holiday notice',
        ], $settings), 'en', $isHome, (new DateTimeImmutable($time, new DateTimeZone('Asia/Shanghai')))->setTimezone(new DateTimeZone('Asia/Shanghai')));
    }

    public function test_visibility_and_language_fallback(): void
    {
        self::assertNull($this->resolve(['status' => 0]));
        self::assertNull($this->resolve([], false));
        self::assertNotNull($this->resolve(['scope' => 'all'], false));
        self::assertNull($this->resolve(['fallback_content' => '  ']));
        self::assertSame('Translated notice', $this->resolve(['content' => ['en' => "Translated\nnotice"]])['text']);
        self::assertSame('Holiday notice', $this->resolve(['content' => ['en' => '', 'zh_cn' => '公告']])['text']);
        self::assertSame('JSON notice', $this->resolve(['content' => '{"en":"JSON notice"}'])['text']);
    }

    public function test_schedule_uses_site_timezone_and_exclusive_end(): void
    {
        $settings = ['starts_at' => '2026-10-01 12:00:00', 'ends_at' => '2026-10-04 00:00:00'];
        self::assertNull($this->resolve($settings, true, '2026-10-01 11:59:59'));
        self::assertNotNull($this->resolve($settings));
        self::assertNotNull($this->resolve($settings, true, '2026-10-03 23:59:59'));
        self::assertNull($this->resolve($settings, true, '2026-10-04 00:00:00'));
        self::assertNotNull($this->resolve(['ends_at' => '2026-10-04 00:00:00']));
        self::assertNull($this->resolve(['ends_at' => '2026-02-30 00:00:00']));
        self::assertNotNull($this->resolve($settings, true, '2026-10-01 04:00:00 UTC'));
    }

    public function test_unsafe_links_and_css_values_are_not_rendered(): void
    {
        $notice = $this->resolve(['link' => 'javascript:alert(1)', 'color' => 'red;display:none', 'background' => 'url(https://example.com)', 'font_size' => 100]);
        self::assertSame('', $notice['link']);
        self::assertSame('#333333', $notice['color']);
        self::assertSame('transparent', $notice['background']);
        self::assertSame(20, $notice['font_size']);
        self::assertSame('https://example.com/contact', $this->resolve(['link' => 'https://example.com/contact'])['link']);
    }

    public function test_admin_validation_allows_end_only_and_rejects_invalid_inputs(): void
    {
        $columns = require dirname(__DIR__, 3) . '/plugins/TopNotice/columns.php';
        $rules   = array_column($columns, 'rules', 'name');
        $factory = new Factory(new Translator(new ArrayLoader(), 'en'));
        self::assertTrue($factory->make(['ends_at' => '2026-10-04 00:00:00'], $rules)->passes());
        self::assertTrue($factory->make(['starts_at' => '', 'ends_at' => '', 'content' => ['en' => 'Notice']], $rules)->passes());
        self::assertFalse($factory->make(['starts_at' => '2026-10-04 00:00:00', 'ends_at' => '2026-10-01 00:00:00'], $rules)->passes());
        self::assertFalse($factory->make(['link' => 'javascript:alert(1)'], $rules)->passes());
        self::assertFalse($factory->make(['content' => ['en' => ['unexpected']]], $rules)->passes());
    }

    public function test_blade_renders_plain_text_safely_with_optional_link(): void
    {
        $cache = sys_get_temp_dir() . '/top-notice-' . uniqid();
        mkdir($cache);
        $files    = new \Illuminate\Filesystem\Filesystem();
        $compiler = new \Illuminate\View\Compilers\BladeCompiler($files, $cache);
        $engines  = new \Illuminate\View\Engines\EngineResolver();
        $engines->register('blade', fn () => new \Illuminate\View\Engines\CompilerEngine($compiler, $files));
        $finder = new \Illuminate\View\FileViewFinder($files, [dirname(__DIR__, 3) . '/plugins/TopNotice/Views']);
        $views  = new \Illuminate\View\Factory($engines, $finder, new \Illuminate\Events\Dispatcher());
        $views->setContainer(new \Illuminate\Container\Container());
        $views->share('__env', $views);

        try {
            $notice = $this->resolve(['fallback_content' => '<script>alert("x")</script>', 'link' => 'https://example.com/contact']);
            $html   = $views->make('shop.notice', compact('notice'))->render();
            self::assertStringContainsString('&lt;script&gt;', $html);
            self::assertStringNotContainsString('<script>alert(', $html);
            self::assertStringContainsString('href="https://example.com/contact"', $html);
            $notice = $this->resolve(['overflow' => 'ellipsis']);
            $html   = $views->make('shop.notice', compact('notice'))->render();
            self::assertStringContainsString('data-mode="ellipsis"', $html);
            self::assertStringNotContainsString('href=', $html);
        } finally {
            foreach (glob($cache . '/*') as $file) {
                unlink($file);
            }
            rmdir($cache);
        }
    }
}
