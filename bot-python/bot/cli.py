import argparse
import asyncio
import re
import sys
from pathlib import Path

from .config import Settings
from .factory import build


async def run(email: str, text: str | None, image: Path | None) -> None:
    portal, assistant = build(Settings.from_env())
    try:
        user = await portal.user_by_email(email)
        media_type = "image/png" if image and image.suffix.lower() == ".png" else "image/jpeg"
        result = await assistant.handle(user["id"], text=text, image=image.read_bytes() if image else None, media_type=media_type)
        print(re.sub(r"<[^>]+>", "", result.to_html()))
    finally:
        await portal.close()


def main() -> None:
    parser = argparse.ArgumentParser(description="Заявка в корзину без Telegram: для отладки")
    parser.add_argument("--email", required=True, help="пользователь клиента, в чью корзину класть")
    parser.add_argument("--image", type=Path, help="фото заявки (нужен ключ Anthropic)")
    parser.add_argument("text", nargs="?", help="текст заявки, по умолчанию читается из stdin")
    args = parser.parse_args()
    text = args.text if args.text is not None else (None if args.image else sys.stdin.read())
    asyncio.run(run(args.email, text, args.image))


if __name__ == "__main__":
    main()
