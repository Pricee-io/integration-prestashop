# Contributing

We welcome contributions from the community. If you have any suggestions or improvements, please feel free to open an issue or submit a pull request. 

## Steps to Contribute

1. Fork the repository.
2. Create a new branch for your feature or bug fix.
3. Make your changes and commit them.
4. Push your changes to your fork.
5. Open a pull request.

## Development

To run the plugin locally, follow these steps:

1. Install Docker and Docker Compose.
2. Clone the repository.
3. Run `make start` to start the development environment with the plugin already installed. Then, youn can access back-office at http://localhost:8081/admin-dev
4. Make your changes.
5. Run `make lint` to lint your code and `make analyze` to analyze your code and fix any issues.
6. Run `make test` (integration tests, needs `make start`) and `make e2e` (end-to-end tests, see below).
7. Commit your changes.
8. Run `make stop` to stop the development environment.

## End-to-end tests

`make e2e` tests the module in a real, throwaway PrestaShop (its own containers, the dev shop is not touched):

1. Installs the last release (1.3.5), configures it, upgrades to the current code and checks the module is still active with its settings kept. Skipped with a warning when the version was not bumped, or when that release cannot be installed on the PrestaShop version.
2. Sends real signed HTTP calls to the webhook and checks the prices written (promotion skipped, replay and bad signature refused, maintenance mode).
3. Runs a sync from the back office in a browser (Playwright, in its Docker image) against a fake Pricee API (`e2e/fake-api.php`) and checks what it received.

It takes a few minutes. Another version: `make e2e PS=1.7.8.11 DB=mariadb:10.6`. `E2E_KEEP=1` leaves the containers running to debug; a browser failure saves a screenshot in `e2e/output/`. CI runs it on every supported version (`.github/workflows/e2e.yml`).

## Packaging

To package the plugin for distribution, follow these steps:

1. Run `make package` to create a zip file of the plugin.
2. Upload the zip file into your PrestaShop instance.
