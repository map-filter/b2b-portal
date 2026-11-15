<x-mail::message>
# Заказ {{ $order->number }}

**{{ $order->company->name }}**, оформил {{ $order->user->name }}.

<x-mail::table>
| Товар | Кол-во | Сумма |
|:--|--:|--:|
@foreach ($order->items as $item)
| {{ $item->name }} | {{ $item->quantity }} {{ $item->unit }} | {{ number_format((float) $item->total, 2, ',', ' ') }} ₸ |
@endforeach
| **Итого** | | **{{ number_format((float) $order->total, 2, ',', ' ') }} ₸** |
</x-mail::table>

@if ($order->comment)
Комментарий клиента: {{ $order->comment }}
@endif

Счёт во вложении.
</x-mail::message>
