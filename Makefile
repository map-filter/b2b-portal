export UID := $(shell id -u)
export GID := $(shell id -g)

DC = docker compose
ART = $(DC) exec core php artisan

.PHONY: up down build logs sh migrate fresh test pint go-test samples exchange-demo

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
	$(ART) migrate:fresh --seed

test:
	$(DC) exec core php artisan test

pint:
	$(DC) exec core ./vendor/bin/pint

go-test:
	infra/go.sh vet ./... && infra/go.sh test ./...

samples:
	infra/go.sh run ./cmd/gen -products $(or $(N),2000) -out /repo/infra/1c-samples

exchange-demo:
	infra/exchange-demo.sh
