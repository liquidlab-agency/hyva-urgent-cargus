# Urgent Cargus — Hyvä Checkout

Hyvä Checkout compatibility for the **Urgent CargusShipGo** shipping module — adds Cargus **Ship & Go** pickup-point (PUDO) selection to the Hyvä (Magewire) checkout.

## Features
- Pickup-point selector shown when a Cargus Ship & Go method is chosen
- Interactive map modal (Leaflet + OpenStreetMap) with point name, address, hours and payment methods
- Selection validated before order placement, saved to the order
- Chosen point shown on the order/account with Google Maps and Waze directions
- Reuses the base module's data and map; no configuration of its own

## Requirements
- Base **Urgent CargusShipGo** module (with Cargus API credentials)
- Hyvä Checkout, Magewire
- Magento 2.4+, PHP 8.1+

## Installation
`composer require liquidlab-agency/hyva-urgent-cargus`

## Documentation
See the [documentation](docs/index.md).
