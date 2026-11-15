<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('demo:reset')]
#[Description('Возвращает демо-данные в исходное состояние')]
class ResetDemo extends Command
{
    public function handle(): int
    {
        $this->call('migrate:fresh', ['--seed' => true, '--force' => true]);
        Storage::deleteDirectory('invoices');
        $this->info('Демо сброшено');

        return self::SUCCESS;
    }
}
