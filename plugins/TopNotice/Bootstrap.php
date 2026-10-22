<?php

namespace Plugin\TopNotice;

use DateTimeImmutable;
use DateTimeZone;
use Plugin\TopNotice\Services\NoticeSettings;

class Bootstrap
{
    public function boot(): void
    {
        add_hook_blade('header.top.language.after', function ($callback, $output, array $data = []): string {
            $notice = NoticeSettings::resolve(
                (array) plugin_setting('top_notice', []),
                locale(),
                request()->routeIs('shop.home.index'),
                new DateTimeImmutable('now', new DateTimeZone(config('app.timezone', 'UTC')))
            );

            return $notice ? view('TopNotice::shop.notice', ['notice' => $notice])->render() : '';
        });
    }
}
