<?php

namespace App\Console\Commands;

use App\Services\Kaspi\KaspiSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

#[Signature('demo:reset')]
#[Description('Возвращает данные в исходное состояние')]
class ResetDemo extends Command
{
    public function handle(): int
    {
        Cache::lock(KaspiSync::LOCK, 600)->block(60, fn () => $this->call('migrate:fresh', ['--seed' => true, '--force' => true]));
        Storage::deleteDirectory('invoices');
        $this->info('Данные сброшены');

        return self::SUCCESS;
    }
}
