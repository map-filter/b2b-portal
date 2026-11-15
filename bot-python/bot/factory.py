from .assistant import OrderAssistant
from .config import Settings
from .parsing import ClaudeParser, FallbackParser, Parser, RegexParser
from .portal import PortalClient


def build(settings: Settings) -> tuple[PortalClient, OrderAssistant]:
    parser: Parser = FallbackParser(ClaudeParser(), RegexParser()) if settings.anthropic_enabled else RegexParser()
    portal = PortalClient(settings.portal_url, settings.integration_token)
    return portal, OrderAssistant(parser, portal)
