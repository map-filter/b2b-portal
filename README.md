# B2B-портал оптовика

Демо-проект: оптовые клиенты сами заказывают товар по ценам своих договоров, менеджеры ведут заказы и клиентов, каталог, цены и остатки приходят из 1С, сделки уходят в Битрикс24.

Данные в демо выдуманные (оптовик электротоваров).

## Архитектура

```mermaid
flowchart LR
    C[Кабинет клиента<br/>React + TS] --> G[nginx]
    M[Панель менеджера<br/>Vue 3 + TS] --> G
    G --> L[Ядро<br/>Laravel API]
    L --> P[(PostgreSQL)]
    L --> R[(Redis<br/>кэш, очереди)]
    L <--> Q{{RabbitMQ}}
    X[Обмен с 1С<br/>Go, CommerceML] --> Q
    ONEC[1С] -- XML --> X
    L -- очередь --> B24[Битрикс24]
    L -- очередь --> TG[Telegram]
    BOT[Бот + AI<br/>Python] --> L
```

| Сервис | Папка | Стек |
|---|---|---|
| Ядро: каталог, компании, цены по договорам, корзина, заказы, роли, REST API | `core-laravel` | PHP 8.4, Laravel 13, Sanctum |
| Кабинет клиента | `client-react` | React, TypeScript, Vite |
| Панель менеджера | `manager-vue` | Vue 3, TypeScript, Vite |
| Обмен с 1С | `exchange-go` | Go |
| Telegram-бот и AI | `bot-python` | Python |
| Инфраструктура | `infra` | Docker, nginx |

## Запуск

Нужны Docker и make.

```bash
cp core-laravel/.env.docker.example core-laravel/.env
make up
docker compose exec core php artisan key:generate
make fresh
```

| Адрес | Что |
|---|---|
| http://127.0.0.1:8201 | кабинет клиента |
| http://127.0.0.1:8201/manager/ | панель менеджера |
| http://127.0.0.1:8201/api/health | состояние ядра |
| http://127.0.0.1:8205 | RabbitMQ (b2b / b2b) |
| http://127.0.0.1:8206 | Mailpit |

Обмен с 1С: `make w2` (порт 8207).

## Команды

| | |
|---|---|
| `make up` / `make down` | поднять / остановить |
| `make fresh` | пересоздать БД с демо-данными |
| `make test` | тесты ядра |
| `make pint` | форматирование PHP |
| `make sh` | shell в контейнере ядра |
