<?php

namespace App\Console\Commands;

use App\Services\Exchange\CatalogSync;
use App\Services\Messaging\RabbitMq;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('exchange:consume')]
#[Description('Принимает каталог, цены и остатки из обмена с 1С через RabbitMQ')]
class ConsumeCatalog extends Command
{
    public function handle(RabbitMq $rabbit, CatalogSync $sync): int
    {
        $this->info('Слушаю очередь core.catalog');

        $rabbit->consume(
            'core.catalog',
            RabbitMq::CATALOG_EXCHANGE,
            ['catalog.#'],
            function (string $key, array $message) use ($sync) {
                $started = microtime(true);
                $sync->handle($key, $message);
                $this->line(sprintf('%s: %d шт. за %d мс', $key, count($message['items'] ?? []), (microtime(true) - $started) * 1000));
            },
            function (string $key, Throwable $exception) {
                Log::error("Обмен с 1С: сообщение {$key} отправлено в core.catalog.dead", ['exception' => $exception]);
                $this->error("{$key}: {$exception->getMessage()}");
            },
        );

        return self::SUCCESS;
    }
}
