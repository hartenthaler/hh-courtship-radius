# Courtship radius for webtrees

[![Latest release](https://img.shields.io/github/v/release/hartenthaler/hh-courtship-radius?label=release)](https://github.com/hartenthaler/hh-courtship-radius/releases/latest)
[![Downloads](https://img.shields.io/github/downloads/hartenthaler/hh-courtship-radius/total)](https://github.com/hartenthaler/hh-courtship-radius/releases)
[![Quality](https://github.com/hartenthaler/hh-courtship-radius/actions/workflows/quality.yml/badge.svg)](https://github.com/hartenthaler/hh-courtship-radius/actions/workflows/quality.yml)

`hh-courtship-radius` is a webtrees 2.2 custom module for analysing the geographic courtship radius of families selected through the tree-specific Clippings Cart.

For each selected spouse, the module uses exactly one distance according to this priority:

1. birthplace of the selected person → residence of the partner at marriage;
2. birthplace of the selected person → marriage place;
3. birthplace of the selected person → birthplace of the partner.

Only FAM records and spouse INDI records explicitly present in the current tree’s Clippings Cart are evaluated. No relatives or families are added implicitly. The courtship radius is the nearest-rank 90th percentile (P90) of the distances in a time interval and is calculated separately for men and women.

## Features

- user-selected from/to years with automatic division into 5–10 equal, rounded time slices;
- P90 time series plus configurable additional percentiles, mean, median, standard deviation, and histograms;
- separate male and female birthplace × destination cross tables;
- Leaflet movement map using the map provider configured in webtrees;
- CSV export of the privacy-filtered observations;
- data-quality report with explicit reasons for excluded cases;
- supplementary list of blood-related spouses, with optional Vesta integration and a webtrees fallback;
- German and English user interface;
- third-party map-service reporting for compatible privacy modules.

## Status

The current stable release is version 0.1.1.

## Requirements

- webtrees 2.2
- the webtrees Clippings Cart module for selecting individuals and families
- at least one enabled webtrees map provider for the map view

Coordinates are resolved from event-level `MAP/LATI/LONG`, a linked `_LOC` record, or the central webtrees place directory, in that order. The module deliberately does not geocode free text.

## Privacy

All records and facts are accessed with the current visitor’s webtrees permissions. The CSV export contains only the observations visible to that visitor. Loading the map may transmit the IP address and browser request data to the map provider enabled by the administrator.

## Translations

- Dutch: [@TheDutchJewel](https://github.com/TheDutchJewel)

## Credits

Developed by Hermann Hartenthaler with assistance from Codex. The project was initialized from the `hh-webtrees-module-template`.

## License

GPL-3.0-or-later, matching webtrees and the established hh module practice.
