start:
	docker compose up -d
	docker compose run --rm --user $(id -u):$(id -g) dev composer install --no-interaction

stop:
	docker compose down

clean:
	docker compose down -v

logs:
	docker compose logs -f

dev:
	docker compose run --rm --user $(id -u):$(id -g) dev bash

lint:
	docker compose run --rm --user $(id -u):$(id -g) dev php vendor/bin/php-cs-fixer fix --verbose

analyze:
	docker compose run --rm --user $(id -u):$(id -g) -e _PS_ROOT_DIR_=/var/www/html dev php vendor/bin/phpstan analyse

rector:
	docker compose run --rm --user $(id -u):$(id -g) dev php vendor/bin/rector process
