<?php

namespace App\Services\Kaspi;

use App\Enums\Kaspi\KaspiState;
use App\Models\Kaspi\KaspiSettings;
use App\Models\Kaspi\KaspiSyncRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Забирает заказы окнами по creationDate (Kaspi принимает период не больше двух недель) по каждому состоянию.
 */
class KaspiSync
{
    public function __construct(private KaspiOrderRecorder $recorder) {}

    public function due(KaspiSettings $settings): bool
    {
        return $settings->is_enabled
            && (! $settings->last_synced_at || $settings->last_synced_at->diffInSeconds(now()) >= $settings->poll_minutes * 60 - 5);
    }

    public function run(string $trigger = 'schedule'): KaspiSyncRun
    {
        return Cache::lock('kaspi-sync', 300)->block(30, fn () => $this->locked($trigger));
    }

    private function locked(string $trigger): KaspiSyncRun
    {
        $settings = KaspiSettings::current();
        $client = new KaspiClient($settings);
        $backfill = $settings->last_synced_at === null;
        $run = KaspiSyncRun::create(['trigger' => $trigger, 'started_at' => now()]);
        $counts = ['fetched' => 0, 'created' => 0, 'updated' => 0];

        try {
            foreach ($this->windows($settings->sync_days) as [$from, $to]) {
                foreach (KaspiState::cases() as $state) {
                    $page = 0;
                    do {
                        $result = $client->orders([
                            '[state]' => $state->value,
                            '[creationDate][$ge]' => $from,
                            '[creationDate][$le]' => $to,
                        ], $page);

                        foreach ($result['data'] as $resource) {
                            $counts['fetched']++;
                            $outcome = $this->recorder->record($client, $resource, $backfill);
                            if ($outcome !== 'same') {
                                $counts[$outcome]++;
                            }
                        }
                        $page++;
                    } while ($page < (int) ($result['meta']['pageCount'] ?? 0));
                }
            }

            $settings->update(['last_synced_at' => now()]);
        } catch (Throwable $exception) {
            Log::warning('Kaspi: синхронизация прервана', ['exception' => $exception]);
            $run->error = $exception->getMessage();
        }

        $run->fill($counts + ['requests' => $client->requests(), 'finished_at' => now()])->save();

        return $run;
    }

    /**
     * @return list<array{int, int}>
     */
    private function windows(int $days): array
    {
        $size = max(1, (int) config('services.kaspi.window_days', 14));
        $end = now();
        $start = now()->subDays(max(1, $days));
        $windows = [];

        for ($from = $start; $from < $end; $from = $from->copy()->addDays($size)) {
            $to = $from->copy()->addDays($size)->subMillisecond()->min($end);
            $windows[] = [$from->getTimestampMs(), $to->getTimestampMs()];
        }

        return $windows;
    }
}
