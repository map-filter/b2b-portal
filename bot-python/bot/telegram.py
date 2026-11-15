import logging
from html import escape

from aiogram import Bot, F, Router
from aiogram.filters import Command, CommandObject, CommandStart
from aiogram.types import Message

from .assistant import OrderAssistant, money
from .parsing import ParseError
from .portal import PortalClient, PortalError

log = logging.getLogger(__name__)
router = Router()

HELP = (
    "Пришлите заявку текстом или фото списка, я подберу товары по вашим ценам и положу их в корзину.\n\n"
    "Например:\n<code>ВВГнг 3х2,5 - 200 м\nавтомат С16 1P - 12 шт\nлампа E27 10 Вт 4000K - 50 шт</code>\n\n"
    "/orders: последние заказы и статусы"
)


async def _current_user(message: Message, portal: PortalClient) -> dict | None:
    user = await portal.user_by_chat(message.chat.id)
    if user is None:
        await message.answer("Сначала привяжите Telegram: в кабинете откройте «Компания» → «Подключить Telegram».")
    return user


@router.message(CommandStart(deep_link=True))
async def start_with_code(message: Message, command: CommandObject, portal: PortalClient) -> None:
    try:
        user = await portal.link(command.args or "", message.chat.id, message.from_user.username if message.from_user else None)
    except PortalError as error:
        await message.answer(escape(str(error)))
        return
    await message.answer(f"Готово, {escape(user['name'])} ({escape(user['company'] or 'сотрудник оптовика')}).\n\n{HELP}")


@router.message(CommandStart())
async def start(message: Message, portal: PortalClient) -> None:
    user = await portal.user_by_chat(message.chat.id)
    if user:
        await message.answer(f"Здравствуйте, {escape(user['name'])}!\n\n{HELP}")
    else:
        await message.answer("Это бот B2B-портала ЭлектроОпт (демо). Привяжите Telegram в кабинете: «Компания» → «Подключить Telegram».")


@router.message(Command("help"))
async def help_(message: Message) -> None:
    await message.answer(HELP)


@router.message(Command("orders"))
async def orders(message: Message, portal: PortalClient) -> None:
    user = await _current_user(message, portal)
    if not user:
        return
    items = await portal.orders(user["id"])
    if not items:
        await message.answer("Заказов пока нет.")
        return
    lines = [f"• <b>{escape(o['number'])}</b>: {escape(o['status_label'])}, {money(o['total'])}" for o in items]
    await message.answer("Последние заказы:\n" + "\n".join(lines))


@router.message(F.photo | F.document.mime_type.startswith("image/"))
async def photo(message: Message, bot: Bot, portal: PortalClient, assistant: OrderAssistant) -> None:
    user = await _current_user(message, portal)
    if not user:
        return
    source = message.photo[-1] if message.photo else message.document
    media_type = message.document.mime_type if message.document else "image/jpeg"
    data = await bot.download(source)
    await _process(message, assistant, user, text=message.caption, image=data.read(), media_type=media_type)


@router.message(F.text)
async def text(message: Message, portal: PortalClient, assistant: OrderAssistant) -> None:
    user = await _current_user(message, portal)
    if user:
        await _process(message, assistant, user, text=message.text)


async def _process(message: Message, assistant: OrderAssistant, user: dict, **payload) -> None:
    if not user["is_client"]:
        await message.answer("Корзина есть только у сотрудников клиента. Менеджерам бот присылает новые заказы.")
        return
    progress = await message.answer("Разбираю заявку…")
    try:
        result = await assistant.handle(user["id"], **payload)
        await progress.edit_text(result.to_html(), disable_web_page_preview=True)
    except ParseError as error:
        await progress.edit_text(escape(str(error)))
    except PortalError as error:
        log.exception("портал вернул ошибку")
        await progress.edit_text(f"Портал не принял заявку: {escape(str(error))}")
