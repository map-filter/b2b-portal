import json
from types import SimpleNamespace

import pytest

from bot.parsing import ClaudeParser, FallbackParser, ParseError, RegexParser


@pytest.fixture
def regex() -> RegexParser:
    return RegexParser()


def test_regex_parses_typical_list(regex):
    lines = regex.parse_text("1. ВВГнг 3х2,5 — 200 м\n- автомат С16 1P 12шт\nлампа E27 10 Вт 4000K: 50")
    assert [(l.query, l.quantity, l.unit) for l in lines] == [
        ("ВВГнг 3х2,5", 200.0, "м"),
        ("автомат С16 1P", 12.0, "шт"),
        ("лампа E27 10 Вт 4000K", 50.0, None),
    ]


def test_regex_keeps_specs_with_commas_and_splits_items(regex):
    lines = regex.parse_text("кабель ВВГ 3х2,5 100м, розетка двойная белая 20 шт; щит на 12 модулей")
    assert [l.query for l in lines] == ["кабель ВВГ 3х2,5", "розетка двойная белая", "щит на 12 модулей"]
    assert lines[2].quantity is None


def test_regex_skips_greetings_and_headers(regex):
    lines = regex.parse_text("Добрый день! Нужно:\nПеречень:\nавтомат С25 — 6 шт\nСпасибо, Анна")
    assert [l.query for l in lines] == ["автомат С25"]


def test_regex_skips_lines_without_words(regex):
    assert regex.parse_text("\n\n 123 \n") == []


async def test_regex_rejects_photo(regex):
    with pytest.raises(ParseError):
        await regex.parse(image=b"x")


class FakeMessages:
    def __init__(self, response=None, error=None):
        self.response, self.error, self.calls = response, error, []

    async def create(self, **kwargs):
        self.calls.append(kwargs)
        if self.error:
            raise self.error
        return self.response


def fake_client(messages: FakeMessages):
    return SimpleNamespace(beta=SimpleNamespace(messages=messages))


def response(payload: dict, stop_reason: str = "end_turn"):
    return SimpleNamespace(stop_reason=stop_reason, content=[SimpleNamespace(type="text", text=json.dumps(payload, ensure_ascii=False))])


async def test_claude_parser_sends_schema_and_image():
    messages = FakeMessages(response({"lines": [{"query": "ВВГнг 3x2.5", "quantity": 200, "unit": "м"}]}))
    lines = await ClaudeParser(fake_client(messages)).parse(text="см. фото", image=b"\x89PNG", media_type="image/png")

    assert lines[0].query == "ВВГнг 3x2.5" and lines[0].quantity == 200
    call = messages.calls[0]
    assert call["model"] == "claude-opus-5-5"
    assert call["output_config"]["format"]["type"] == "json_schema"
    assert call["fallbacks"] == "default"
    assert call["messages"][0]["content"][0]["source"]["media_type"] == "image/png"


async def test_claude_refusal_becomes_parse_error_and_text_falls_back():
    messages = FakeMessages(response({"lines": []}, stop_reason="refusal"))
    claude = ClaudeParser(fake_client(messages))

    with pytest.raises(ParseError):
        await claude.parse(text="ВВГ 3х1,5 100 м")

    lines = await FallbackParser(claude, RegexParser()).parse(text="ВВГ 3х1,5 100 м")
    assert lines[0].query == "ВВГ 3х1,5" and lines[0].quantity == 100


async def test_photo_error_is_not_masked_by_fallback():
    claude = ClaudeParser(fake_client(FakeMessages(response({}, stop_reason="max_tokens"))))
    with pytest.raises(ParseError):
        await FallbackParser(claude, RegexParser()).parse(image=b"x")
