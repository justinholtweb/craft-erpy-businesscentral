# Erpy for Business Central

An **[Erpy](https://github.com/justinholtweb/craft-erpy)** connector for Microsoft Dynamics 365 Business Central.

Free. Erpy itself is the paid part — it owns the sync engine, the identity map, the field
mapping, the queue, the dead letters and the log. This package's whole job is to translate one
vendor's API into Erpy's canonical documents.

## Installing

```sh
composer require justinholtweb/craft-erpy-businesscentral
php craft plugin/install erpy-businesscentral
```

Then add a connection under **Erpy → Connections** and pick it from the ERP list.

## What you need to know

### Authentication

OAuth 2.0 client credentials against Entra ID — the grant Microsoft steers integrations towards, because nobody sits in front of a nightly stock sync.

### Delta syncing

Yes, on `lastModifiedDateTime`, for every entity except prices.

### Known limits

Business Central's standard API v2.0 does not expose sales prices or posted shipment tracking numbers. Both are optional endpoint settings here: publish a page as an API in your own extension and name it, or leave them blank and Erpy simply will not sync that entity. Credit limit is the same — surface it on your customers page and name the field.

## What it syncs

The connection screen shows exactly which entities and directions this connector supports —
it is generated from the connector's own declaration, so it can never advertise a flow it has
not implemented.

## A field is wrong

Correct it on the mapping screen: a rule whose target is a canonical field (`sku`, `unitPrice`,
`customerCode`) overrides what the connector read, before anything reaches Commerce. No fork,
no wait for a release.

## Requirements

Craft CMS 5.3+, Craft Commerce 5.0+, PHP 8.2+, and Erpy 5.0+.

## Support

justin@justinholt.com
