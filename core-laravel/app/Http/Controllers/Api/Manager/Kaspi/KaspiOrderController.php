<?php

namespace App\Http\Controllers\Api\Manager\Kaspi;

use App\Enums\Kaspi\KaspiCancelReason;
use App\Enums\Kaspi\KaspiState;
use App\Http\Controllers\Controller;
use App\Http\Resources\Kaspi\KaspiOrderResource;
use App\Models\Kaspi\KaspiOrder;
use App\Models\Kaspi\KaspiSettings;
use App\Services\Kaspi\KaspiApiException;
use App\Services\Kaspi\KaspiClient;
use App\Services\Kaspi\KaspiOrderActions;
use App\Services\Kaspi\KaspiStock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class KaspiOrderController extends Controller
{
    /**
     * Заказы Kaspi по разделам кабинета (state), с поиском по номеру, покупателю и телефону.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'state' => ['nullable', Rule::enum(KaspiState::class)],
            'q' => ['nullable', 'string', 'max:50'],
            'problem' => ['nullable', 'boolean'],
        ]);

        $orders = KaspiOrder::query()
            ->when($request->filled('state'), fn (Builder $q) => $q->where('state', $request->string('state')))
            ->when($request->boolean('problem'), fn (Builder $q) => $q->whereNotNull('problem'))
            ->when($request->filled('q'), fn (Builder $q) => $q->where(fn ($q) => $q
                ->where('code', 'ilike', '%'.$request->string('q').'%')
                ->orWhere('customer_name', 'ilike', '%'.$request->string('q').'%')
                ->orWhere('customer_phone', 'like', '%'.preg_replace('/\D/', '', (string) $request->string('q')).'%')))
            ->with('order')
            ->withCount('entries')
            ->orderByDesc('kaspi_created_at')
            ->paginate(25)
            ->withQueryString();

        $counts = KaspiOrder::query()->selectRaw('state, count(*) as total')->groupBy('state')->pluck('total', 'state');
        $settings = KaspiSettings::current();

        return KaspiOrderResource::collection($orders)->additional(['meta' => [
            'states' => collect(KaspiState::cases())->map(fn (KaspiState $state) => [
                'value' => $state->value,
                'label' => $state->label(),
                'count' => (int) ($counts[$state->value] ?? 0),
            ]),
            'problems' => KaspiOrder::whereNotNull('problem')->whereNotIn('status', ['CANCELLED', 'RETURNED'])->count(),
            'last_synced_at' => $settings->last_synced_at?->toIso8601String(),
            'mode' => $settings->mode->value,
        ]]);
    }

    public function show(KaspiOrder $order, KaspiStock $stock): KaspiOrderResource
    {
        $order->load(['entries.product', 'events.user', 'order']);
        $order->entries->each(fn ($entry) => $entry->stock = $entry->product_id ? $stock->available($entry->product_id, $entry->store_id ?? $order->store_id) : null);

        return new KaspiOrderResource($order);
    }

    public function accept(Request $request, KaspiOrder $order, KaspiOrderActions $actions, KaspiStock $stock): KaspiOrderResource
    {
        return $this->show($actions->accept($order, $request->user()), $stock);
    }

    public function assemble(Request $request, KaspiOrder $order, KaspiOrderActions $actions, KaspiStock $stock): KaspiOrderResource
    {
        $data = $request->validate(['places' => ['required', 'integer', 'min:1', 'max:50']]);

        return $this->show($actions->assemble($order, $data['places'], $request->user()), $stock);
    }

    public function sendCode(Request $request, KaspiOrder $order, KaspiOrderActions $actions, KaspiStock $stock): KaspiOrderResource
    {
        return $this->show($actions->sendCode($order, $request->user()), $stock);
    }

    public function complete(Request $request, KaspiOrder $order, KaspiOrderActions $actions, KaspiStock $stock): KaspiOrderResource
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^\d{4,6}$/']]);

        return $this->show($actions->complete($order, $data['code'], $request->user()), $stock);
    }

    public function cancel(Request $request, KaspiOrder $order, KaspiOrderActions $actions, KaspiStock $stock): KaspiOrderResource
    {
        $data = $request->validate([
            'reason' => ['required', Rule::enum(KaspiCancelReason::class)],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->show($actions->cancel($order, KaspiCancelReason::from($data['reason']), $data['comment'] ?? null, $request->user()), $stock);
    }

    /**
     * Накладная Kaspi Доставки: PDF скачивается из Kaspi с токеном продавца.
     */
    public function waybill(KaspiOrder $order): Response
    {
        abort_unless($order->waybill_url, 404, 'Накладная ещё не сформирована');

        try {
            $pdf = KaspiClient::make()->download($order->waybill_url);
        } catch (KaspiApiException $exception) {
            abort(502, $exception->getMessage());
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"kaspi-{$order->code}.pdf\"",
        ]);
    }

    public function reasons(): array
    {
        return ['data' => collect(KaspiCancelReason::cases())->map(fn ($reason) => ['value' => $reason->value, 'label' => $reason->label()])];
    }
}
