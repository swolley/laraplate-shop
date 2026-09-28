<p>&nbsp;</p>
<p align="center">
	<a href="https://github.com/swolley" target="_blank">
		<img src="https://raw.githubusercontent.com/swolley/images/refs/heads/master/logo_laraplate.png?raw=true" width="400" alt="Laraplate Logo" />
    </a>
</p>
<p>&nbsp;</p>

> ⚠️ **Caution**: This package is a **work in progress**. **Don't use this in production or use at your own risk**—no guarantees are provided.

## Table of Contents

-   [Description](#description)
-   [Installation](#installation)
-   [Configuration](#configuration)
-   [Current Bootstrap Status](#current-bootstrap-status)
-   [Roadmap](#roadmap)
-   [Scripts](#scripts)
-   [Contributing](#contributing)
-   [License](#license)

## Description

The Shop Module provides the online sales channel for Laraplate.
It orchestrates CMS content, ERP items and SAO support into a storefront.

At this stage, the module is intentionally initialized with a minimal structure to support incremental, test-driven development.

## Installation

If you want to add this module to your project, you can use the `joshbrw/laravel-module-installer` package.

Add repository to your `composer.json` file:

```json
"repositories": [
    {
        "type": "composer",
        "url": "https://github.com/swolley/laraplate-core.git"
    },
    {
        "type": "composer",
        "url": "https://github.com/swolley/laraplate-cms.git"
    },
    {
        "type": "composer",
        "url": "https://github.com/swolley/laraplate-erp.git"
    },
    {
        "type": "composer",
        "url": "https://github.com/swolley/laraplate-sao.git"
    },
    {
        "type": "composer",
        "url": "https://github.com/swolley/laraplate-shop.git"
    }
]
```

```bash
composer require joshbrw/laravel-module-installer swolley/laraplate-core swolley/laraplate-cms swolley/laraplate-erp swolley/laraplate-sao swolley/laraplate-shop
```

Then, you can install the module by running the following command:

```bash
php artisan module:install Core
php artisan module:install CMS
php artisan module:install ERP
php artisan module:install SAO
php artisan module:install Shop
```

## Configuration

The module configuration is automatically mapped as `shop.*` when the module is active.
Configuration file: `Modules/Shop/config/config.php`.

> The module defines no environment variables yet. They will be added as domain features are introduced.

## Current Bootstrap Status

-   Module metadata (`module.json`) configured with provider registration
-   Service providers scaffolded (`ShopServiceProvider`, `RouteServiceProvider`, `EventServiceProvider`)
-   Base folders for HTTP, config, routes, resources, database, and tests in place
-   Composer package scaffolded with autoload mappings
-   Independent git repository registered as the `Modules/Shop` submodule
-   Not yet enabled in `modules_statuses.json`

## Roadmap

Planned next steps for Shop domain design and implementation:

-   Catalog exposure of ERP items enriched with CMS content
-   Cart and checkout workflows
-   Order handoff to ERP sales orders
-   Customer support integration through SAO

## Scripts

```bash
# Local formatting (dirty files only from project root)
vendor/bin/pint --dirty
```

## Contributing

If you want to contribute to this project, follow these steps:

1. Fork the repository.
2. Create a new branch for your feature or correction.
3. Send a pull request.

## License

Shop Module is open-sourced software licensed under the [GNU AGPL v3](https://www.gnu.org/licenses/agpl-3.0.html).
