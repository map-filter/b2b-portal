import os
from dataclasses import dataclass


@dataclass(frozen=True)
class Settings:
    telegram_token: str
    portal_url: str
    integration_token: str
    rabbitmq_url: str
    anthropic_enabled: bool
    cabinet_url: str

    @classmethod
    def from_env(cls) -> "Settings":
        return cls(
            telegram_token=os.getenv("TELEGRAM_BOT_TOKEN", ""),
            portal_url=os.getenv("PORTAL_URL", "http://nginx"),
            integration_token=os.getenv("INTEGRATION_TOKEN", ""),
            rabbitmq_url=os.getenv("RABBITMQ_URL", "amqp://guest:guest@rabbitmq:5672/"),
            anthropic_enabled=bool(os.getenv("ANTHROPIC_API_KEY") or os.getenv("ANTHROPIC_AUTH_TOKEN")),
            cabinet_url=os.getenv("CABINET_URL", "http://127.0.0.1:8201"),
        )
