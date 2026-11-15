from dataclasses import dataclass, field
from html import escape

from .parsing import OrderLine, Parser
from .portal import PortalClient


@dataclass
class AssistantResult:
    added: list[dict] = field(default_factory=list)
    alternatives: dict[str, list[dict]] = field(default_factory=dict)
    not_found: list[str] = field(default_factory=list)
    cart: dict | None = None

    def to_html(self) -> str:
        if not self.added and not self.not_found:
            return "Не нашёл в сообщении товарных позиций. Пришлите список: товар и количество на каждой строке."

        parts: list[str] = []
        if self.added:
            parts.append(f"✅ <b>Добавил в корзину: {len(self.added)}</b>")
            for item in self.added:
                line = f"• {escape(item['name'])} × {item['quantity']} {escape(item['unit'])} — {_money(item['price'])}/{escape(item['unit'])}"
                if item["quantity"] != item["requested"]:
                    line += f" <i>(мин. партия {item['quantity']})</i>"
                parts.append(line)
                others = self.alternatives.get(item["sku"], [])
                if others:
                    parts.append("  <i>похожие: " + escape("; ".join(o["name"] for o in others)) + "</i>")
        if self.not_found:
            parts.append("\n❓ <b>Не нашёл, уточните:</b>")
            parts.extend(f"• {escape(query)}" for query in self.not_found)
        if self.cart:
            parts.append(f"\n🛒 В корзине {self.cart['count']} поз. на {_money(self.cart['total'])}")
            parts.append(f'<a href="{escape(self.cart["url"])}">Открыть корзину и оформить</a>')
        return "\n".join(parts)


class OrderAssistant:
    def __init__(self, parser: Parser, portal: PortalClient):
        self.parser = parser
        self.portal = portal

    async def handle(self, user_id: int, text: str | None = None, image: bytes | None = None, media_type: str = "image/jpeg") -> AssistantResult:
        lines = await self.parser.parse(text, image, media_type)
        return await self.fill_cart(user_id, lines)

    async def fill_cart(self, user_id: int, lines: list[OrderLine]) -> AssistantResult:
        result = AssistantResult()
        if not lines:
            return result

        matches = await self.portal.match(user_id, [{"query": line.query, "quantity": line.quantity or 1} for line in lines])

        to_cart: list[dict] = []
        for row in matches:
            candidates = row["candidates"]
            if not candidates:
                result.not_found.append(row["query"])
                continue
            best = candidates[0]
            quantity = max(row["quantity"], best["min_quantity"])
            to_cart.append({"product_id": best["product_id"], "quantity": quantity})
            result.added.append({**best, "quantity": quantity, "requested": row["quantity"]})
            result.alternatives[best["sku"]] = [c for c in candidates[1:] if c["score"] >= best["score"] - 0.001]

        if to_cart:
            result.cart = await self.portal.add_to_cart(user_id, to_cart)
        return result


def _money(value: str | float) -> str:
    return f"{float(value):,.2f}".replace(",", " ").replace(".", ",") + " ₸"
