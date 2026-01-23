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
3. Run `make start` to start the development environment with the plugin already installed.
4. Make your changes.
5. Run `make lint` to lint your code and `make analyze` to analyze your code and fix any issues.
6. Commit your changes.
7. Run `make stop` to stop the development environment.

## Packaging

To package the plugin for distribution, follow these steps:

1. Run `make package` to create a zip file of the plugin.
2. Upload the zip file into your PrestaShop instance.
