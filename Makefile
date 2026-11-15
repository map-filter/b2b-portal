export UID := $(shell id -u)
export GID := $(shell id -g)

DC = docker compose
ART = $(DC) exec core php artisan

.PHONY: up down build logs sh migrate fresh test pint w2

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

w2:
	$(DC) --profile w2 up -d --build
