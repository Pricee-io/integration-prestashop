#!/bin/sh
# Rebuild the test database (test_<db>) as a copy of the PrestaShop database.
# Run inside the database container: `sh -s < tests/prepare-test-db.sh`.
#
# - The faceted search module is unhooked in the copy: its product hooks run
#   TRUNCATE, which would commit the transaction each test is rolled back with.
# - MULTISTORE=1 adds a second shop and turns multistore on in the copy;
#   MULTISTORE=0 turns it off. PrestaShop before 8 keeps the multistore state
#   for the whole process, so the tests cannot switch it: the compatibility CI
#   runs them once in each mode.
set -e

DB="${DB_NAME:-prestashop}"
TEST_DB="test_${DB}"
ROOT_PASSWORD="${DB_ROOT_PASSWORD:-root}"
SQL="mariadb -uroot -p${ROOT_PASSWORD}"

$SQL -e "DROP DATABASE IF EXISTS ${TEST_DB}; CREATE DATABASE ${TEST_DB}; GRANT ALL ON ${TEST_DB}.* TO prestashop@'%';"
mariadb-dump -uroot -p"${ROOT_PASSWORD}" "${DB}" | $SQL "${TEST_DB}"

$SQL "${TEST_DB}" -e "DELETE hm FROM ps_hook_module hm JOIN ps_module m ON m.id_module = hm.id_module WHERE m.name = 'ps_facetedsearch'"

if [ -n "${MULTISTORE:-}" ]; then
    if [ "${MULTISTORE}" = "1" ]; then
        $SQL "${TEST_DB}" -e "
            INSERT INTO ps_shop (id_shop_group, name, color, id_category, theme_name, active, deleted)
            SELECT id_shop_group, 'Pricee test shop', '', id_category, theme_name, 1, 0 FROM ps_shop
            WHERE (SELECT COUNT(*) FROM ps_shop) < 2 ORDER BY id_shop LIMIT 1;"
    fi
    $SQL "${TEST_DB}" -e "
        DELETE FROM ps_configuration WHERE name = 'PS_MULTISHOP_FEATURE_ACTIVE';
        INSERT INTO ps_configuration (name, value, date_add, date_upd) VALUES ('PS_MULTISHOP_FEATURE_ACTIVE', '${MULTISTORE}', NOW(), NOW());"
fi

echo "Test database ${TEST_DB} ready"
