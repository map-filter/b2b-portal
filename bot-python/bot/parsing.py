import json
import re
from typing import Protocol

import anthropic
from pydantic import BaseModel, Field, ValidationError

MODEL = "claude-opus-5-5"

SYSTEM_PROMPT = """Ты разбираешь заявки оптовых покупателей электротоваров: кабель, провод, автоматы, УЗО, розетки, лампы, щиты.
Заявка приходит текстом или фотографией (рукописный список, скриншот, накладная).
Выдели каждую товарную позицию: query — описание товара так, как его стоит искать в каталоге, с марками и характеристиками
(сечение, ток, полюса, цоколь, мощность, цвет), но без количества; quantity — число; unit — единица («м», «шт», «уп») или null.
Не придумывай позиции, которых нет в заявке. Приветствия, адреса, подписи и итоговые суммы — не позиции."""

SCHEMA = {
    "type": "object",
    "properties": {
        "lines": {
            "type": "array",
            "items": {
                "type": "object",
                "properties": {
                    "query": {"type": "string"},
                    "quantity": {"type": ["number", "null"]},
                    "unit": {"type": ["string", "null"]},
                },
                "required": ["query", "quantity", "unit"],
                "additionalProperties": False,
            },
        }
    },
    "required": ["lines"],
    "additionalProperties": False,
}


class OrderLine(BaseModel):
    query: str = Field(min_length=1)
    quantity: float | None = None
    unit: str | None = None


class ParsedRequest(BaseModel):
    lines: list[OrderLine]


class ParseError(Exception):
    pass


class Parser(Protocol):
    async def parse(self, text: str | None = None, image: bytes | None = None, media_type: str = "image/jpeg") -> list[OrderLine]: ...


_QUANTITY_AT_END = re.compile(
    r"[\s\-–—:x×*]*(\d+(?:[.,]\d+)?)\s*(шт|штук[аи]?|м|метр(?:ов|а)?|уп(?:ак\w*)?|pcs|m)?\.?\s*$",
    re.IGNORECASE,
)
_SPLIT_LINES = re.compile(r"\n|;|,(?=\s*[^\d\s])")
_BULLET = re.compile(r"^\s*(?:\d+[.)]|[-•*])\s+")
_CHATTER = re.compile(
    r"^(добр\w*\s+(день|утро|вечер)|здравствуй\w*|привет\w*|спасибо\w*|с уважением\w*|нужно|надо|прошу\w*|заявка\w*|счёт|счет)\b",
    re.IGNORECASE,
)


class RegexParser:
    """Разбор без ИИ: каждая строка или пункт через «;» — позиция, количество в конце строки."""

    async def parse(self, text: str | None = None, image: bytes | None = None, media_type: str = "image/jpeg") -> list[OrderLine]:
        if image is not None:
            raise ParseError("Фото разбирает только ИИ, а он сейчас выключен. Пришлите заявку текстом.")
        return self.parse_text(text or "")

    def parse_text(self, text: str) -> list[OrderLine]:
        lines: list[OrderLine] = []
        for raw in _SPLIT_LINES.split(text):
            line = _BULLET.sub("", raw).strip(" \t-–—")
            if not line or _CHATTER.match(line) or line.endswith(":"):
                continue
            quantity, unit = None, None
            match = _QUANTITY_AT_END.search(line)
            if match and (match.group(2) or re.search(r"[-–—:x×*]\s*\d+(?:[.,]\d+)?\s*$", line)):
                quantity = float(match.group(1).replace(",", "."))
                unit = _normalize_unit(match.group(2))
                line = line[: match.start()].strip(" \t-–—:")
            looks_like_item = quantity is not None or re.search(r"\d", line) or len(line.split()) >= 2
            if looks_like_item and re.search(r"[A-Za-zА-Яа-яЁё]", line):
                lines.append(OrderLine(query=line, quantity=quantity, unit=unit))
        return lines


def _normalize_unit(unit: str | None) -> str | None:
    if not unit:
        return None
    unit = unit.lower()
    if unit.startswith(("шт", "pcs")):
        return "шт"
    if unit.startswith(("м", "m")):
        return "м"
    return "уп"


class ClaudeParser:
    """Разбор текста и фото через Claude со строгой JSON-схемой ответа."""

    def __init__(self, client: anthropic.AsyncAnthropic | None = None):
        self.client = client or anthropic.AsyncAnthropic()

    async def parse(self, text: str | None = None, image: bytes | None = None, media_type: str = "image/jpeg") -> list[OrderLine]:
        content: list[dict] = []
        if image is not None:
            import base64

            content.append({
                "type": "image",
                "source": {"type": "base64", "media_type": media_type, "data": base64.standard_b64encode(image).decode()},
            })
        content.append({"type": "text", "text": text or "Разбери заявку на фото."})

        try:
            response = await self.client.beta.messages.create(
                model=MODEL,
                max_tokens=16000,
                system=SYSTEM_PROMPT,
                messages=[{"role": "user", "content": content}],
                output_config={"effort": "low", "format": {"type": "json_schema", "schema": SCHEMA}},
                betas=["server-side-fallback-2026-07-01"],
                fallbacks="default",
            )
        except anthropic.RateLimitError as error:
            raise ParseError("ИИ сейчас перегружен, попробуйте через минуту") from error
        except anthropic.APIStatusError as error:
            raise ParseError(f"ИИ недоступен ({error.status_code})") from error
        except anthropic.APIConnectionError as error:
            raise ParseError("Нет связи с ИИ") from error

        if response.stop_reason == "refusal":
            raise ParseError("ИИ не смог обработать эту заявку, пришлите её текстом")
        if response.stop_reason == "max_tokens":
            raise ParseError("Заявка слишком большая, разделите её на части")

        text_block = next((block.text for block in response.content if block.type == "text"), None)
        if text_block is None:
            raise ParseError("ИИ вернул пустой ответ")
        try:
            return ParsedRequest.model_validate(json.loads(text_block)).lines
        except (json.JSONDecodeError, ValidationError) as error:
            raise ParseError("ИИ вернул неожиданный ответ") from error


class FallbackParser:
    """Сначала ИИ, при ошибке с текстом — разбор регулярками."""

    def __init__(self, primary: Parser, fallback: RegexParser):
        self.primary = primary
        self.fallback = fallback

    async def parse(self, text: str | None = None, image: bytes | None = None, media_type: str = "image/jpeg") -> list[OrderLine]:
        try:
            return await self.primary.parse(text, image, media_type)
        except ParseError:
            if image is not None or not text:
                raise
            return self.fallback.parse_text(text)
