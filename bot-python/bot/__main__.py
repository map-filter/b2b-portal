import asyncio
import logging
import sys

from aiogram import Bot, Dispatcher
from aiogram.client.default import DefaultBotProperties
from aiogram.enums import ParseMode

from .config import Settings
from .events import consume_status_changes
from .factory import build
from .telegram import router


async def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
    settings = Settings.from_env()
    if not settings.telegram_token:
        logging.error("TELEGRAM_BOT_TOKEN не задан, бот не запущен")
        sys.exit(1)

    portal, assistant = build(settings)
    logging.info("разбор заявок: %s", "Claude + запасной парсер" if settings.anthropic_enabled else "без ИИ (нет ключа Anthropic)")

    bot = Bot(settings.telegram_token, default=DefaultBotProperties(parse_mode=ParseMode.HTML))
    dispatcher = Dispatcher(portal=portal, assistant=assistant)
    dispatcher.include_router(router)

    try:
        await asyncio.gather(
            dispatcher.start_polling(bot),
            consume_status_changes(bot, settings.rabbitmq_url, settings.cabinet_url),
        )
    finally:
        await portal.close()
        await bot.session.close()


if __name__ == "__main__":
    asyncio.run(main())
