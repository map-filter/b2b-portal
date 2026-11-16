import json

import httpx

from bot.assistant import OrderAssistant, money
from bot.events import status_message
from bot.parsing import RegexParser
from bot.portal import PortalClient


def portal_with(handler) -> PortalClient:
    return PortalClient("http://portal", "secret", transport=httpx.MockTransport(handler))


async def test_assistant_matches_and_fills_cart_respecting_min_quantity():
    requests = []

    def handler(request: httpx.Request) -> httpx.Response:
        requests.append(request)
        assert request.headers["Authorization"] == "Bearer secret"
        if request.url.path.endswith("/match"):
            body = json.loads(request.content)
            assert body["lines"] == [{"query": "ВВГнг 3х2,5", "quantity": 50.0}, {"query": "что-то странное", "quantity": 1}]
            return httpx.Response(200, json={"data": [
                {"query": "ВВГнг 3х2,5", "quantity": 50, "candidates": [
                    {"product_id": 7, "sku": "EO-1003", "name": "Кабель ВВГнг 3x2.5", "unit": "м", "min_quantity": 100, "price": "496.64", "stock": 900, "score": 1.02},
                    {"product_id": 8, "sku": "EO-1010", "name": "Кабель ВВГ 3x2.5", "unit": "м", "min_quantity": 100, "price": "422.14", "stock": 0, "score": 0.51},
                ]},
                {"query": "что-то странное", "quantity": 1, "candidates": []},
            ]})
        if request.url.path.endswith("/cart"):
            assert json.loads(request.content) == {"items": [{"product_id": 7, "quantity": 100}]}
            return httpx.Response(200, json={"count": 1, "total": "49664.00", "url": "http://cabinet/cart"})
        return httpx.Response(404)

    portal = portal_with(handler)
    result = await OrderAssistant(RegexParser(), portal).handle(42, text="ВВГнг 3х2,5 — 50 м\nчто-то странное")
    await portal.close()

    assert [r.url.path for r in requests] == ["/api/integration/users/42/match", "/api/integration/users/42/cart"]
    html = result.to_html()
    assert "Добавил в корзину: 1" in html
    assert "мин. партия 100" in html
    assert "что-то странное" in html
    assert "49 664,00 ₸" in html
    assert "похожие" not in html


async def test_request_without_items_does_not_call_portal():
    portal = portal_with(lambda request: httpx.Response(500))
    result = await OrderAssistant(RegexParser(), portal).handle(1, text="")
    await portal.close()
    assert "Не нашёл в сообщении товарных позиций" in result.to_html()


def test_status_message_only_for_linked_users():
    event = {"id": 5, "number": "B2B-001005", "status": "shipped", "status_label": "Отгружен", "status_comment": "Машина <в пути>", "user": {"telegram_chat_id": 77}}
    text = status_message(event, "http://cabinet/")
    assert "🚚" in text and "отгружен" in text and "&lt;в пути&gt;" in text and "http://cabinet/orders/5" in text
    assert status_message({**event, "user": {"telegram_chat_id": None}}, "http://cabinet") is None


def test_money_format():
    assert money("1234567.5") == "1 234 567,50 ₸"
