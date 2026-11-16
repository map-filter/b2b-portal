<?php

namespace App\Console\Commands;

use App\Services\Kaspi\KaspiSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kaspi:sync {--force : Не ждать интервала из настроек}')]
#[Description('Забирает заказы из Kaspi Магазина')]
class SyncKaspiOrders extends Command
{
    public function handle(KaspiSync $sync): int
    {
        $run = $this->option('force') ? $sync->run() : $sync->runScheduled();

        if (! $run) {
            return self::SUCCESS;
        }

        if ($run->error) {
            $this->error("Kaspi: {$run->error}");

            return self::FAILURE;
        }

        $this->info("Kaspi: получено {$run->fetched}, новых {$run->created}, обновлено {$run->updated}, запросов {$run->requests}");

        return self::SUCCESS;
    }
}
