# Tessera Orders

The record of what was sold: orders with their lines and payment, the list and detail of an order, voiding an order, marking a credit sale paid, receipts, and the figures Customers and Reports read. It has no till of its own: the [point of sale](https://github.com/Tessera-kit/tessera-pos) and any other way a sale can arrive (a storefront, an import) place orders through it.

A module for [Tessera](https://github.com/Tessera-kit/tessera-kit). It needs a project made from one of the Tessera starters.

## Install

```bash
php artisan module:add orders
bun run build
```

`module:add` requires `tessera/tessera-orders`, runs the migrations and gives the roles the new permissions. Without it:

```bash
composer config repositories.tessera composer https://tessera-kit.github.io/tessera-kit
composer require tessera/tessera-orders
php artisan migrate
php artisan modules:sync
bun run build
```

## What it needs

- Other Tessera modules: `catalog`, `tenant`, `customers`, `inventory`, `team`. Composer installs them for you.

## What it adds

| Plan feature | Permissions |
|---|---|
| Orders | view orders, view all orders, void orders, export orders |

Menu entry: Orders. Exports the orders report.

- **`PlaceOrderAction`** places an order for a cart: it prices the cart on the server, asks the `CartPricing` contract for discounts, writes the order with its lines and payment, takes the stock out, and is safe to repeat for the same offline id. Whatever brings the sale can add attributes of its own to the order.
- Fills the `CustomerActivity` and `RevenueSource` contracts: customers show their orders, and net profit can use the revenue.
- Works without Discounts, Reports and the till.

## Part of the sales split

Orders and the point of sale used to be one module, Sales. Orders keeps the data and the order screens; the till is the Pos module, which requires this one. The `sales` module is now a bundle that installs both.

## Part of Tessera

This repository is a read-only copy of a folder in the [tessera-kit](https://github.com/Tessera-kit/tessera-kit) monorepo. Open issues and pull requests there, not here.

## License

All rights reserved until a license is added to the monorepo.
