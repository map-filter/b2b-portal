<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('demo:reset')
    ->dailyAt('04:30')
    ->timezone('Asia/Almaty')
    ->when(fn () => config('app.demo_reset'))
    ->withoutOverlapping();
