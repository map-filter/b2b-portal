<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Счёт {{ $order->number }}</title>
    <style>
        * { font-family: "DejaVu Sans", sans-serif; }
        body { font-size: 10px; color: #1e293b; }
        h1 { font-size: 15px; margin: 18px 0 4px; }
        table { width: 100%; border-collapse: collapse; }
        .bank td { border: 1px solid #94a3b8; padding: 4px 6px; vertical-align: top; }
        .items th, .items td { border: 1px solid #94a3b8; padding: 4px 5px; }
        .items th { background: #f1f5f9; font-weight: bold; }
        .num { text-align: right; white-space: nowrap; }
        .muted { color: #64748b; }
        .totals td { padding: 2px 5px; }
        .demo { background: #fef3c7; color: #92400e; padding: 4px 8px; text-align: center; font-size: 9px; }
        .sign { margin-top: 30px; }
        .sign td { padding-top: 18px; }
    </style>
</head>
<body>
    <div class="demo">Демо-документ, реквизиты выдуманы</div>

    <table class="bank" style="margin-top: 10px;">
        <tr>
            <td style="width: 55%;"><b>Бенефициар:</b><br>{{ $supplier['name'] }}<br>БИН {{ $supplier['bin'] }}</td>
            <td><b>ИИК</b><br>{{ $supplier['iik'] }}</td>
            <td style="width: 10%;"><b>Кбе</b><br>{{ $supplier['kbe'] }}</td>
        </tr>
        <tr>
            <td><b>Банк бенефициара:</b><br>{{ $supplier['bank'] }}</td>
            <td><b>БИК</b><br>{{ $supplier['bik'] }}</td>
            <td><b>Код назначения платежа</b><br>710</td>
        </tr>
    </table>

    <h1>Счёт на оплату № {{ $order->number }} от {{ $order->created_at->format('d.m.Y') }}</h1>
    <div class="muted">Действителен до {{ $validUntil->format('d.m.Y') }}</div>

    <table style="margin: 12px 0;">
        <tr><td style="width: 110px;" class="muted">Поставщик:</td><td>{{ $supplier['name'] }}, БИН {{ $supplier['bin'] }}, {{ $supplier['address'] }}</td></tr>
        <tr><td class="muted">Покупатель:</td><td>{{ $order->company->legal_name }}, БИН {{ $order->company->bin }}, {{ $order->company->legal_address }}</td></tr>
        <tr><td class="muted">Договор:</td><td>{{ $order->contract->number }} от {{ $order->contract->starts_at->format('d.m.Y') }}</td></tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 22px;">№</th>
                <th>Код</th>
                <th>Наименование</th>
                <th class="num">Кол-во</th>
                <th>Ед.</th>
                <th class="num">Цена</th>
                <th class="num">Сумма</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td class="num">{{ $loop->iteration }}</td>
                    <td style="white-space: nowrap;">{{ $item->sku }}</td>
                    <td>{{ $item->name }}</td>
                    <td class="num">{{ $item->quantity }}</td>
                    <td>{{ $item->unit }}</td>
                    <td class="num">{{ number_format((float) $item->price, 2, ',', ' ') }}</td>
                    <td class="num">{{ number_format((float) $item->total, 2, ',', ' ') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals" style="margin-top: 6px;">
        <tr><td class="num" style="width: 85%;"><b>Итого:</b></td><td class="num"><b>{{ number_format((float) $order->total, 2, ',', ' ') }}</b></td></tr>
        <tr><td class="num">В том числе НДС {{ $supplier['vat_percent'] }}%:</td><td class="num">{{ number_format((float) $vat, 2, ',', ' ') }}</td></tr>
    </table>

    <p>Всего наименований {{ $order->items->count() }}, на сумму {{ number_format((float) $order->total, 2, ',', ' ') }} KZT</p>

    <table class="sign">
        <tr>
            <td style="width: 50%;">Исполнитель ____________________ /Демо И.И./</td>
            <td>Главный бухгалтер ____________________ /Демо Б.Б./</td>
        </tr>
    </table>
</body>
</html>
