<?php

namespace App\Services\Kaspi;

use App\Enums\Kaspi\KaspiCancelReason;
use App\Enums\Kaspi\KaspiStatus;
use App\Models\Kaspi\KaspiOrder;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Действия продавца с заказом через Kaspi API. После каждого запроса заказ перечитывается из Kaspi.
 */
class KaspiOrderActions
{
    public function __construct(private KaspiOrderRecorder $recorder) {}

    public function accept(KaspiOrder $order, ?User $by = null): KaspiOrder
    {
        $this->allowed($order, 'accept');

        return $this->call($order, $by, 'Заказ принят', ['code' => $order->code, 'status' => KaspiStatus::AcceptedByMerchant->value]);
    }

    public function assemble(KaspiOrder $order, int $places, ?User $by = null): KaspiOrder
    {
        $this->allowed($order, 'assemble');

        return $this->call($order, $by, "Запрошена накладная Kaspi Доставки, мест: {$places}", ['status' => 'ASSEMBLE', 'numberOfSpace' => (string) $places]);
    }

    public function sendCode(KaspiOrder $order, ?User $by = null): KaspiOrder
    {
        $this->allowed($order, 'send_code');
        $order = $this->call($order, $by, 'Покупателю отправлен код в приложении Kaspi.kz', ['code' => $order->code, 'status' => KaspiStatus::Completed->value], [
            'X-Security-Code' => '',
            'X-Send-Code' => 'true',
        ]);
        $order->update(['code_sent_at' => now()]);

        return $order;
    }

    public function complete(KaspiOrder $order, string $securityCode, ?User $by = null): KaspiOrder
    {
        $this->allowed($order, 'complete');

        return $this->call($order, $by, 'Заказ выдан покупателю по коду', ['code' => $order->code, 'status' => KaspiStatus::Completed->value], [
            'X-Security-Code' => $securityCode,
            'X-Send-Code' => 'true',
        ]);
    }

    public function cancel(KaspiOrder $order, KaspiCancelReason $reason, ?string $comment, ?User $by = null): KaspiOrder
    {
        $this->allowed($order, 'cancel');

        return $this->call($order, $by, "Заказ отменён: {$reason->label()}", array_filter([
            'code' => $order->code,
            'status' => KaspiStatus::Cancelled->value,
            'cancellationReason' => $reason->value,
            'cancellationComment' => $comment,
        ]));
    }

    private function allowed(KaspiOrder $order, string $action): void
    {
        if (! in_array($action, $order->availableActions(), true)) {
            throw ValidationException::withMessages(['action' => 'Это действие недоступно для заказа в текущем статусе']);
        }
    }

    private function call(KaspiOrder $order, ?User $by, string $text, array $attributes, array $headers = []): KaspiOrder
    {
        $client = KaspiClient::make();

        try {
            $client->changeStatus($order->kaspi_id, $attributes, $headers);
        } catch (KaspiApiException $exception) {
            $order->log('error', $exception->getMessage(), $by?->id);

            throw ValidationException::withMessages(['kaspi' => $exception->getMessage()]);
        }

        $order->log('action', $text, $by?->id);

        if ($fresh = $client->orderByCode($order->code)) {
            $this->recorder->record($client, $fresh, false);
        }

        return $order->refresh();
    }
}
