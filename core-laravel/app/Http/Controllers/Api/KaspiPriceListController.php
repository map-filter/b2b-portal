<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kaspi\KaspiSettings;
use App\Services\Kaspi\KaspiPriceList;
use Illuminate\Http\Response;

class KaspiPriceListController extends Controller
{
    /**
     * Прайс-лист для Kaspi: ссылку указывают в кабинете продавца, Kaspi забирает файл сам.
     *
     * @unauthenticated
     */
    public function __invoke(string $token, KaspiPriceList $priceList): Response
    {
        abort_unless(hash_equals(KaspiSettings::current()->pricelist_token, $token), 404);

        return response($priceList->xml(), 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    }
}
