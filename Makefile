# The module autoloader (pricee/vendor, not versioned) must exist before the
# shop starts: the auto-install installs every module on disk, including the
# mounted pricee/, whose main file requires it
start:
	docker compose build --no-cache dev
	docker compose run --rm --user $$(id -u):$$(id -g) dev bash -c "cd /app/pricee && composer dump-autoload"
	docker compose up -d --remove-orphans
	docker compose run --rm --user $$(id -u):$$(id -g) dev composer install --no-interaction

stop:
	docker compose down

clean:
	docker compose down -v --remove-orphans

logs:
	docker compose logs -f

dev:
	docker compose run --rm --user $$(id -u):$$(id -g) dev bash

lint:
	docker compose run --rm --user $$(id -u):$$(id -g) dev php vendor/bin/php-cs-fixer fix

lint_ci:
	docker compose run --rm --user $$(id -u):$$(id -g) dev php vendor/bin/php-cs-fixer fix --dry-run --diff

# PHPStan boots PrestaShop, which creates its cache folders in the shop
# volume: run as its owner (www-data), like the web server and `make test`
analyze:
	docker compose run --rm --user www-data -e _PS_ROOT_DIR_=/var/www/html dev php vendor/bin/phpstan analyse --memory-limit=1G

analyze_ci:
	docker compose run --rm --user www-data -e _PS_ROOT_DIR_=/var/www/html dev php vendor/bin/phpstan analyse --error-format github --memory-limit=1G

# Integration tests, run inside the PrestaShop container against its install.
# They use PrestaShop's test mode, i.e. the `test_prestashop` database, rebuilt
# here as a copy of the dev database (tests/prepare-test-db.sh): the dev data
# is never touched.
# Run as www-data so the PrestaShop cache stays writable by the web server.
test:
	docker compose exec -T mysql sh -s < tests/prepare-test-db.sh
	docker compose exec -T --user www-data -w /app prestashop php vendor/bin/phpunit $(FILTER)

rector:
	docker compose run --rm --user $$(id -u):$$(id -g) dev php vendor/bin/rector process

package:
	docker compose run --rm --user $$(id -u):$$(id -g) dev bash -c "cd /app/pricee && composer dump-autoload"
	rm -f pricee.zip
	zip -r pricee.zip pricee
