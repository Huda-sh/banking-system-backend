.PHONY: build up down restart logs shell migrate fresh seed test optimize

# Docker commands
build:
	docker compose build

up:
	docker compose up -d

down:
	docker compose down

restart:
	docker compose restart

logs:
	docker compose logs -f

shell:
	docker compose exec app bash

# Artisan commands
migrate:
	docker compose exec app php artisan migrate

fresh:
	docker compose exec app php artisan migrate:fresh

seed:
	docker compose exec app php artisan db:seed

test:
	docker compose exec app php artisan test

optimize:
	docker compose exec app php artisan optimize

# Composer commands
composer-install:
	docker compose exec app composer install

composer-update:
	docker compose exec app composer update

# NPM commands
npm-install:
	docker compose exec app npm install

npm-build:
	docker compose exec app npm run build

npm-dev:
	docker compose exec app npm run dev

# Setup commands
setup:
	@echo "Setting up the application..."
	@docker compose build
	@docker compose up -d
	@sleep 10
	@docker compose exec app php artisan key:generate
	@docker compose exec app php artisan migrate
	@echo "Setup complete! Visit http://localhost:8080"

# Deployment
deploy:
	@bash deploy.sh