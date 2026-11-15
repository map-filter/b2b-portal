from typing import Any

import httpx


class PortalError(Exception):
    def __init__(self, status: int, message: str):
        super().__init__(message)
        self.status = status


class PortalClient:
    def __init__(self, base_url: str, token: str, transport: httpx.AsyncBaseTransport | None = None):
        self.http = httpx.AsyncClient(
            base_url=base_url.rstrip("/") + "/api/integration",
            headers={"Authorization": f"Bearer {token}", "Accept": "application/json"},
            timeout=20,
            transport=transport,
        )

    async def _call(self, method: str, path: str, **kwargs: Any) -> Any:
        response = await self.http.request(method, path, **kwargs)
        if response.is_error:
            try:
                message = response.json().get("message", response.text)
            except ValueError:
                message = response.text
            raise PortalError(response.status_code, message)
        return response.json()

    async def link(self, code: str, chat_id: int, username: str | None) -> dict:
        return (await self._call("POST", "/telegram/link", json={"code": code, "chat_id": chat_id, "username": username}))["data"]

    async def user_by_chat(self, chat_id: int) -> dict | None:
        try:
            return (await self._call("GET", f"/telegram/users/{chat_id}"))["data"]
        except PortalError as error:
            if error.status == 404:
                return None
            raise

    async def user_by_email(self, email: str) -> dict:
        return (await self._call("GET", "/users", params={"email": email}))["data"]

    async def match(self, user_id: int, lines: list[dict]) -> list[dict]:
        return (await self._call("POST", f"/users/{user_id}/match", json={"lines": lines}))["data"]

    async def add_to_cart(self, user_id: int, items: list[dict]) -> dict:
        return await self._call("POST", f"/users/{user_id}/cart", json={"items": items})

    async def orders(self, user_id: int) -> list[dict]:
        return (await self._call("GET", f"/users/{user_id}/orders"))["data"]

    async def close(self) -> None:
        await self.http.aclose()
