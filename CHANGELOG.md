# Change Log

## Next release

- Add Dutch translations; thanks to @TheDutchJewel.
- Preserve quotation marks around nicknames in plain-text person names.

## 0.1.1 - 2026-07-15

- Fix the period filter form so that applying a different year range stays on the chart route.
- Add the estimated consanguinity rate for marriages in the selected period.
- Document the population-level consanguinity rate and distinguish it from Wright's individual inbreeding coefficient.
- Improve chart labels, use integer time-series ticks, combine the male and female histograms, and simplify the statistics table.
- Collapse the cross tables and data-quality exclusions by default and make the exclusion columns sortable.
- Place map reference circles automatically on the most frequent birthplace and use the mean time-slice P90 as their radius.
- Reuse existing webtrees core translations through `MoreI18N` instead of duplicating them in the module catalog.
- Return third-party-service data categories in the list format required by the `hh_legal_notice` privacy-notice contract.

## 0.1.0 - 2026-07-15

- Initial functional implementation of the courtship-radius chart.
- Analyse only families and spouse individuals explicitly selected in the tree-specific Clippings Cart.
- Apply the prioritised birthplace-to-residence, marriage-place, and partner-birthplace distance rule.
- Add separate male and female P90 time series, statistics, histograms, and cross tables.
- Add Leaflet movement map, CSV export, data-quality report, and blood-relationship information.
- Add module settings for percentiles and cross-table sorting.
- Add German translations, privacy-provider reporting, and unit tests.
- Read the tree-specific session cart directly instead of relying on the core Clippings Cart module's activation status.
