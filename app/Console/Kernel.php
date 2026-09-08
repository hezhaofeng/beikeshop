<?php

namespace App\Console;

use Beike\Console\Commands\BeikeShopInstall;
use Beike\Console\Commands\Sequence;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        BeikeShopInstall::class,
        Sequence::class,
    ];

    /**
     * Define the application's command schedule.
     *
     * @param Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('process:order')->everyFiveMinutes();

        $paypalB = plugin('paypal_b');
        if ($paypalB && $paypalB->getEnabled()) {
            $schedule->command('paypal-b:dispatch-callbacks')->everyMinute();
        }

        $paypalA = plugin('paypal_a');
        if ($paypalA && $paypalA->getEnabled()) {
            // 同步补投单条最长 30 秒，必须防止上一轮未跑完就重入。
            $schedule->command('paypal-a:dispatch-fulfillment')
                ->everyFiveMinutes()
                ->withoutOverlapping();
        }
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $paths = [
            __DIR__ . '/Commands',
            base_path('beike/Console/Commands'),
        ];
        $this->load($paths);

        require base_path('routes/console.php');
    }
}
