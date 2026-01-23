start:
	docker compose up -d --remove-orphans
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
	docker compose run --rm --user $(id -u):$(id -g) dev php vendor/bin/php-cs-fixer fix

lint_ci:
	docker compose run --rm --user $(id -u):$(id -g) dev php vendor/bin/php-cs-fixer fix --dry-run --diff

analyze:
	docker compose run --rm --user $(id -u):$(id -g) -e _PS_ROOT_DIR_=/var/www/html dev php vendor/bin/phpstan analyse

analyze_ci:
	docker compose run --rm --user $(id -u):$(id -g) -e _PS_ROOT_DIR_=/var/www/html dev php vendor/bin/phpstan analyse --error-format github

rector:
	docker compose run --rm --user $(id -u):$(id -g) dev php vendor/bin/rector process

package:
	docker compose run --rm --user $(id -u):$(id -g) dev bash -c "cd /app/pricee && composer dump-autoload"
	rm -f pricee.zip
	zip -r pricee.zip pricee
