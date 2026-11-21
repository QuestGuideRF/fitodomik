from __future__ import annotations
from contextlib import asynccontextmanager
from typing import Any, AsyncIterator, Iterable, Mapping, Sequence, Union
import aiomysql
from aiomysql.cursors import DictCursor
from bot.config import Settings
Params = Union[Sequence[Any], Mapping[str, Any]]
class Database:
    def __init__(self, settings: Settings) -> None:
        self._settings = settings
        self._pool: aiomysql.Pool | None = None
    @property
    def pool(self) -> aiomysql.Pool:
        if self._pool is None:
            raise RuntimeError("Database pool is not connected")
        return self._pool
    async def connect(self) -> None:
        if self._pool is not None:
            return
        self._pool = await aiomysql.create_pool(
            host=self._settings.db_host,
            port=self._settings.db_port,
            user=self._settings.db_user,
            password=self._settings.db_password,
            db=self._settings.db_name,
            charset="utf8mb4",
            init_command="SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            autocommit=True,
            minsize=1,
            maxsize=8,
            connect_timeout=10,
        )
    async def close(self) -> None:
        if self._pool is None:
            return
        self._pool.close()
        await self._pool.wait_closed()
        self._pool = None
    async def ping(self) -> None:
        await self.fetch_value("SELECT 1")
    @asynccontextmanager
    async def transaction(self) -> AsyncIterator[aiomysql.Connection]:
        async with self.pool.acquire() as connection:
            await connection.begin()
            try:
                yield connection
            except BaseException:
                await connection.rollback()
                raise
            else:
                await connection.commit()
    async def fetch_one(
        self,
        query: str,
        params: Params = (),
        *,
        connection: aiomysql.Connection | None = None,
    ) -> dict[str, Any] | None:
        if connection is not None:
            return await self._fetch_one(connection, query, params)
        async with self.pool.acquire() as acquired:
            return await self._fetch_one(acquired, query, params)
    async def fetch_all(
        self,
        query: str,
        params: Params = (),
        *,
        connection: aiomysql.Connection | None = None,
    ) -> list[dict[str, Any]]:
        if connection is not None:
            return await self._fetch_all(connection, query, params)
        async with self.pool.acquire() as acquired:
            return await self._fetch_all(acquired, query, params)
    async def fetch_value(self, query: str, params: Params = ()) -> Any:
        async with self.pool.acquire() as connection:
            async with connection.cursor() as cursor:
                await cursor.execute(query, params)
                row = await cursor.fetchone()
        return row[0] if row else None
    async def execute(
        self,
        query: str,
        params: Params = (),
        *,
        connection: aiomysql.Connection | None = None,
    ) -> int:
        if connection is not None:
            return await self._execute(connection, query, params)
        async with self.pool.acquire() as acquired:
            return await self._execute(acquired, query, params)
    async def insert(
        self,
        query: str,
        params: Params = (),
        *,
        connection: aiomysql.Connection | None = None,
    ) -> int:
        if connection is not None:
            return await self._insert(connection, query, params)
        async with self.pool.acquire() as acquired:
            return await self._insert(acquired, query, params)
    async def executemany(self, query: str, rows: Iterable[Params]) -> int:
        async with self.pool.acquire() as connection:
            async with connection.cursor() as cursor:
                return int(await cursor.executemany(query, list(rows)))
    @staticmethod
    async def _fetch_one(
        connection: aiomysql.Connection, query: str, params: Params
    ) -> dict[str, Any] | None:
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute(query, params)
            row = await cursor.fetchone()
        return dict(row) if row else None
    @staticmethod
    async def _fetch_all(
        connection: aiomysql.Connection, query: str, params: Params
    ) -> list[dict[str, Any]]:
        async with connection.cursor(DictCursor) as cursor:
            await cursor.execute(query, params)
            rows = await cursor.fetchall()
        return [dict(row) for row in rows]
    @staticmethod
    async def _execute(
        connection: aiomysql.Connection, query: str, params: Params
    ) -> int:
        async with connection.cursor() as cursor:
            return int(await cursor.execute(query, params))
    @staticmethod
    async def _insert(
        connection: aiomysql.Connection, query: str, params: Params
    ) -> int:
        async with connection.cursor() as cursor:
            await cursor.execute(query, params)
            return int(cursor.lastrowid)