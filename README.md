# Freelancer services

An extension for [Modulento](https://github.com/alex01at/modulento) that turns
the catalogue into a marketplace for services.

It adds the offer type `freelancer.service`: up to three packages (Basic,
Standard, Premium), each with a price, a delivery time and a number of
revisions, up to three extras that can be booked with any package, and a note
on what the provider needs from the buyer - all with a text per language. Its
order flow covers how such an offer is ordered and carried out: the provider
accepts or declines, delivers, the buyer accepts the delivery or asks for a
revision, and both sides can agree on a cancellation. An order that is not
accepted within three days expires; a delivery that is not answered within
seven days counts as accepted.

## Requirements

Modulento 0.11.0 or newer, which provides interface version 1 in the form
this extension uses (`OfferType::validate()` receives the offer's id since
that release).

Expiry and automatic acceptance are carried out by Modulento's scheduled
tasks, so the cron has to run (**Administration → Tasks**).

## Installing

In Modulento, open **Administration → Packages**, enter
`alex01at/modulento-ext-freelancer` and install. Then enable "Freelancer
services" under **Administration → Extensions**; this creates the tables.
New versions appear on the Packages page.

By hand: unpack a release into `extensions/freelancer/` of the installation
and enable the extension.

## Data

The extension keeps its data in tables of its own, all starting with
`x_freelancer_`: `x_freelancer_package`, `x_freelancer_package_translation`,
`x_freelancer_extra`, `x_freelancer_extra_translation` and
`x_freelancer_requirement`. They reference the core's offers and go with
them. Removing the package leaves the tables in place.

## Changing the look

The templates in `templates/` are rendered as `@freelancer/<file>.twig`. Do
not edit them here - an update would replace the files. A theme overrides a
template by bringing a file of the same name in
`themes/<theme>/extensions/freelancer/`.

## Development

The tests are part of the Modulento repository and expect the extension at
`extensions/freelancer` there - for example as a symlink to this clone:

```
ln -s /path/to/modulento-ext-freelancer /path/to/modulento/extensions/freelancer
cd /path/to/modulento && php tests/run.php
```

This repository itself only checks the syntax of its PHP files on every push.

## Releasing

Set `version` in `extension.json`, commit, then tag and push:

```
git tag v0.2.1 && git push origin main v0.2.1
```

The workflow checks that tag and `extension.json` agree, builds
`modulento-ext-freelancer-<version>.zip` with its SHA-256 and publishes both
as a GitHub Release.

## Licence

GPL-3.0-or-later, see `LICENSE`.
