export UID := $(shell id -u)
export GID := $(shell id -g)

DC = docker compose
ART = $(DC) exec core php artisan

.PHONY: up down build logs sh migrate fresh test pint go-test samples exchange-demo bot bot-test bot-demo

up:
	$(DC) up -d --build

down:
	$(DC) down

build:
	$(DC) build

logs:
	$(DC) logs -f --tail=100

sh:
	$(DC) exec core sh

migrate:
	$(ART) migrate

fresh:
	$(ART) demo:reset

test:
	$(DC) exec core php artisan test

pint:
	$(DC) exec core ./vendor/bin/pint

go-test:
	infra/go.sh vet ./... && infra/go.sh test ./...

samples:
	infra/go.sh run ./cmd/gen -products $(or $(N),2000) -out /repo/infra/1c-samples

exchange-demo:
	set -a; . ./.env; set +a; infra/exchange-demo.sh

bot:
	$(DC) --profile bot up -d --build bot

bot-test:
	docker build -q -t b2b-bot-test -f bot-python/Dockerfile.test bot-python >/dev/null
	docker run --rm -v $(CURDIR)/bot-python:/app -w /app b2b-bot-test python -m pytest -q

bot-demo:
	docker run --rm -i --network $(or $(COMPOSE_PROJECT_NAME),b2b)_default --env-file .env -e PORTAL_URL=http://nginx -v $(CURDIR)/bot-python:/app -w /app b2b-bot-test python -m bot.cli --email $(or $(EMAIL),buyer@energomontazh.demo)
