import json
import logging
from html import escape

import aio_pika
from aiogram import Bot

log = logging.getLogger(__name__)

EVENTS_EXCHANGE = "b2b.events"
QUEUE = "bot.order_status"

ICONS = {"confirmed": "✅", "assembling": "📦", "shipped": "🚚", "completed": "🏁", "cancelled": "❌"}


def status_message(event: dict, cabinet_url: str) -> str | None:
    chat_id = (event.get("user") or {}).get("telegram_chat_id")
    if not chat_id:
        return None
    text = f"{ICONS.get(event['status'], 'ℹ️')} Заказ <b>{escape(event['number'])}</b>: {escape(event['status_label'].lower())}"
    if event.get("status_comment"):
        text += f"\n<i>{escape(event['status_comment'])}</i>"
    return text + f'\n<a href="{cabinet_url.rstrip("/")}/orders/{event["id"]}">Открыть заказ</a>'


async def consume_status_changes(bot: Bot, rabbitmq_url: str, cabinet_url: str) -> None:
    connection = await aio_pika.connect_robust(rabbitmq_url)
    async with connection:
        channel = await connection.channel()
        await channel.set_qos(prefetch_count=10)
        exchange = await channel.declare_exchange(EVENTS_EXCHANGE, aio_pika.ExchangeType.TOPIC, durable=True)
        queue = await channel.declare_queue(QUEUE, durable=True)
        await queue.bind(exchange, "order.status_changed")
        log.info("слушаю статусы заказов")

        async with queue.iterator() as messages:
            async for message in messages:
                async with message.process(requeue=False):
                    event = json.loads(message.body)
                    text = status_message(event, cabinet_url)
                    if text:
                        await bot.send_message(event["user"]["telegram_chat_id"], text, disable_web_page_preview=True)
