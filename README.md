[![License](https://img.shields.io/github/license/Blair2004/NexoPOS)](LICENSE)

<p align="center">
  <img src="public/svg/zashpos-variant-1.svg" alt="ZashPOS" width="200"/>
</p>

# ZashPOS

ZashPOS is a modern, extensible point-of-sale platform for retail stores, restaurants, cafés, warehouses, and other product- or service-based businesses. It brings sales, inventory, customers, purchasing, accounting, reporting, and staff operations together in one application.

**Based on [NexoPOS](https://github.com/Blair2004/NexoPOS).** This repository is a branded fork of NexoPOS v6.3.0 (Laravel 12, Vue 3, Tailwind 4). Upstream project, documentation, and marketplace links below still refer to NexoPOS where that is the external product.

### Deployment
Upstream NexoPOS partners with InstaPods for deployment. Those options remain available for this core:

[![Deploy On Nexo Cloud](https://my.nexopos.com/deploy-on-nexo-cloud-button-light.svg)](https://nexopos.cloud?utm_channel=github.com&utm_campaign=readme)
[![Deploy On InstaPods](https://instapods.com/deploy-button.svg)](https://app.instapods.com/dashboard/pods/create?app=nexopos&ref=nexopos)

## What the core provides

The ZashPOS core (NexoPOS-compatible) includes:

- Product, category, unit, tax, and customer management
- POS sales, orders, payments, receipts, refunds, and registers
- Inventory, procurement, stock history, and low-stock workflows
- Accounting and operational reporting, including configurable stock and cost visibility
- Customer groups, rewards, coupons, and store configuration
- User roles, permissions, activity tracking, and audit-friendly workflows
- Barcode and scale barcode support
- Media management, dashboard widgets, and extensibility APIs
- A module system for adding business-specific features without changing the core

ZashPOS is designed to start as a single-store POS and grow with the business. Multi-store, restaurant, service, workforce, rental, AI, printing, and other workflows are delivered through modules (including our first-party **ZashDine** restaurant module).

## Marketplace modules and ecosystem

Compatible modules can still be obtained from the [NexoPOS Marketplace](https://my.nexopos.com/en/marketplace) when they match this core version. A dedicated **ZashMarket** is planned for first-party ZashPOS add-ons.

See the [official NexoPOS documentation](https://my.nexopos.com/en/documentation) for environment, installation, Windows, cloud, and local-network guides.

## Quick start for developers

ZashPOS is a Laravel application using Vue, Vite, Tailwind CSS, and a first-class module architecture. The root project currently targets PHP 8.2+ (8.4 recommended) and Node.js 24.

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan storage:link
npm install
npm run build
```

Configure the database and application URL in `.env`, then run the normal Laravel migrations and workers required by your deployment.

Create a new module with the built-in generator:

```bash
php artisan make:module
```

Custom module source normally lives under `modules/` and contains its own routes, providers, migrations, resources, assets, settings, and tests.

## Demo and resources

- [Try NexoPOS demo](https://demo.nexopos.com) (upstream)
- [My NexoPOS](https://my.nexopos.com/en)
- [Official documentation](https://my.nexopos.com/en/documentation)
- [Marketplace](https://my.nexopos.com/en/marketplace)
- [NexoPOS REST API](https://docs.api.nexopos.com)
- [Upstream GitHub releases](https://github.com/Blair2004/NexoPOS/releases)

## License

ZashPOS is based on NexoPOS and remains open source software licensed under the [GNU General Public License v3.0](LICENSE).
