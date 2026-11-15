# Эмулятор Kaspi Магазина

Отвечает как Kaspi Shop API v2 из [справочника для продавцов](https://guide.kaspi.kz/partner/ru/shop/api), чтобы модуль Kaspi можно было разрабатывать и показывать без настоящего магазина. Go, только стандартная библиотека, состояние в `/data/state.json`.

## API (как у Kaspi)

Все запросы с заголовком `X-Auth-Token`, ответы `application/vnd.api+json` (JSON:API). POST без `Content-Type: application/vnd.api+json` получает 415, неверный токен 401, ошибки в виде `{"errors":[{"status":"400","title":"..."}]}`.

| Метод и путь | Что |
|---|---|
| `GET /shop/api/v2/orders` | список: `page[number]` с 0, `page[size]` до 100, `filter[orders][state]`, `[status]`, `[creationDate][$ge]` / `[$le]` в мс (обязателен `$ge`, период до 14 дней), `[deliveryType]`, `[signatureRequired]`, `[code]`, `include[orders]=user`; в `meta` `pageCount` и `totalCount` |
| `GET /shop/api/v2/orders/{id}` | заказ |
| `GET /shop/api/v2/orders/{id}/entries` | позиции: `quantity`, `basePrice`, `totalPrice`, `category`, связи `product` и `deliveryPointOfService` |
| `GET /shop/api/v2/orders/{id}/user` | покупатель |
| `GET /shop/api/v2/orderentries/{id}/product` | товар Kaspi (`masterproducts`) |
| `GET /shop/api/v2/masterproducts/{id}/merchantProduct` | товар продавца, `code` = артикул из прайс-листа |
| `GET /shop/api/v2/pointofservices/{id}` | склад продавца и адрес |
| `POST /shop/api/v2/orders` | смена статуса, см. ниже |
| `PUT /shop/api/orderPartialCancel/{id}` | изменить количество позиций принятого заказа |
| `GET /shop/api/waybill/{code}.pdf` | накладная Kaspi Доставки (PDF) |

Смена статуса `POST /orders` с телом `{"data":{"type":"orders","id":"...","attributes":{...}}}`:

| `status` | Когда можно | Что происходит |
|---|---|---|
| `ACCEPTED_BY_MERCHANT` | NEW и APPROVED_BY_BANK | раздел становится PICKUP, DELIVERY или KASPI_DELIVERY по способу доставки |
| `ASSEMBLE` + `numberOfSpace` | принятый заказ Kaspi Доставки | появляются `waybill`, `waybillNumber`; через `KASPI_EMU_COURIER_AFTER` курьер забирает, ещё через столько же заказ выполнен |
| `COMPLETED` | принятый самовывоз или своя доставка | первый запрос с `X-Send-Code: true` и пустым `X-Security-Code` отправляет покупателю код (он виден в консоли), второй с кодом завершает заказ |
| `CANCELLED` + `cancellationReason` | APPROVED_BY_BANK или ACCEPTED_BY_MERCHANT, до передачи курьеру | причины `BUYER_CANCELLATION_BY_MERCHANT`, `BUYER_NOT_REACHABLE`, `MERCHANT_OUT_OF_STOCK`, комментарий до 1000 знаков |
| `ARRIVED` | принятый предзаказ | товар прибыл на склад |

Ограничение периода в 14 дней в справочнике не описано, но встречается в интеграциях с Kaspi, поэтому модуль всегда запрашивает окнами по 14 дней.

## Прайс-лист

Эмулятор забирает прайс-лист продавца по `KASPI_EMU_PRICELIST_URL` раз в `KASPI_EMU_PRICELIST_EVERY`, как это делает Kaspi по ссылке из кабинета. Формат `kaspi_catalog`: `company`, `merchantid`, `offers/offer[@sku]` с `model`, `brand`, `availabilities/availability[@available,@storeId,@stockCount]` и `price` (или `cityprices`). Новые заказы создаются только из товаров в наличии.

## Консоль

`GET /emu/`: прайс-лист, последние заказы, «телефон покупателя» с кодами выдачи, кнопки «Покупатель оформил заказ», «Покупатель отменил», «Оформить возврат», «Сброс». JSON для тестов: `GET /emu/api/state`, `POST /emu/api/orders`, `POST /emu/api/reset`, `POST /emu/api/pricelist` (загрузить XML вручную).

## Настройки

| Переменная | По умолчанию | |
|---|---|---|
| `KASPI_EMU_TOKEN` | `demo-kaspi-token` | ожидаемый `X-Auth-Token` |
| `KASPI_EMU_PUBLIC_URL` | `http://kaspi-emu:8080` | база для ссылок `links` и накладных |
| `KASPI_EMU_PRICELIST_URL` | | откуда забирать прайс-лист |
| `KASPI_EMU_PRICELIST_EVERY` | `1m` | как часто |
| `KASPI_EMU_NEW_ORDER_EVERY` | `4m` | новый заказ покупателя, `0` выключает |
| `KASPI_EMU_HISTORY_DAYS` / `KASPI_EMU_ORDERS_PER_DAY` | `60` / `6` | история при первом запуске |
| `KASPI_EMU_SIGN_AFTER` | `2m` | через сколько покупатель подписывает кредит |
| `KASPI_EMU_COURIER_AFTER` | `3m` | шаги Kaspi Доставки |
| `KASPI_EMU_ACCEPT_TIMEOUT` | `0` | автоотмена непринятого заказа, `0` выключает |
| `KASPI_EMU_MAX_RANGE_DAYS` | `14` | максимальный период `creationDate` |

```bash
make kaspi-test   # go vet и тесты
```
