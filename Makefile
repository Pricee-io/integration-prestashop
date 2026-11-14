start:
	docker compose build --no-cache dev
	docker compose up -d --remove-orphans
	docker compose run --rm --user $(id -u):$(id -g) dev composer install --no-interaction

stop:
	docker compose down

clean:
	docker compose down -v --remove-orphans

logs:
	docker compose logs -f

dev:
	docker compose run --rm --user $(id -u):$(id -g) dev bash

lint:
	docker compose run --rm --user $(id -u):$(id -g) dev php vendor/bin/php-cs-fixer fix

lint_ci:
	docker compose run --rm --user $(id -u):$(id -g) dev php vendor/bin/php-cs-fixer fix --dry-run --diff

analyze:
	docker compose run --rm --user $(id -u):$(id -g) -e _PS_ROOT_DIR_=/var/www/html dev php vendor/bin/phpstan analyse --memory-limit=1G

analyze_ci:
	docker compose run --rm --user $(id -u):$(id -g) -e _PS_ROOT_DIR_=/var/www/html dev php vendor/bin/phpstan analyse --error-format github --memory-limit=1G

# Integration tests, run inside the PrestaShop container against its install.
# They use PrestaShop's test mode, i.e. the `test_prestashop` database, rebuilt
# here as a copy of the dev database: the dev data is never touched. The
# faceted search module is disabled in the copy because its product hooks run
# TRUNCATE, which would commit the transaction each test is rolled back with.
# Run as www-data so the PrestaShop cache stays writable by the web server.
test:
	docker compose exec -T mysql sh -c '\
		mariadb -uroot -proot -e "DROP DATABASE IF EXISTS test_prestashop; CREATE DATABASE test_prestashop; GRANT ALL ON test_prestashop.* TO prestashop@\"%\";" \
		&& mariadb-dump -uroot -proot prestashop | mariadb -uroot -proot test_prestashop \
		&& mariadb -uroot -proot test_prestashop -e "DELETE hm FROM ps_hook_module hm JOIN ps_module m ON m.id_module = hm.id_module WHERE m.name = \"ps_facetedsearch\""'
	docker compose exec -T --user www-data -w /app prestashop php vendor/bin/phpunit $(FILTER)

rector:
	docker compose run --rm --user $(id -u):$(id -g) dev php vendor/bin/rector process

package:
	docker compose run --rm --user $(id -u):$(id -g) dev bash -c "cd /app/pricee && composer dump-autoload"
	rm -f pricee.zip
	zip -r pricee.zip pricee
