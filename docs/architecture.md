# Courtship Radius Module - Architecture

## Purpose

The module analyses the geographic courtship radius of a user-defined set of families and individuals from the tree-specific Clippings Cart. It does not expand the selection by adding relatives or further families. The marriage date assigns every evaluated family to a time slice.

The courtship radius is calculated separately for men and women. For each selected spouse, exactly one distance is used according to this priority:

1. birthplace of the selected spouse to the partner's residence at marriage;
2. birthplace of the selected spouse to the marriage place;
3. birthplace of the selected spouse to the partner's birthplace.

If none of these distances can be determined, the spouse is excluded from the distance statistics and the reason is shown in the data-quality report.

## Data flow

1. `CartXrefService` reads the exact XREFs stored in the current tree's Clippings Cart.
2. `CartAnalysisService` resolves selected families and spouses, checks visibility, extracts marriage dates, chooses the prioritised destination, and records one marriage-level blood-relationship result per family.
3. `PlaceResolver` obtains coordinates from event-level `MAP/LATI/LONG`, linked `_LOC` records, or the webtrees place directory. It does not geocode free text.
4. `ReportService` limits observations and marriages to the requested year range, creates rounded time slices, and calculates statistics, cross tables, map data, and the consanguinity rate.
5. The chart view renders the report. The CSV export contains the privacy-filtered distance observations.

## Time slices and distance statistics

The requested interval is divided into five to ten equal, rounded slices. The primary courtship-radius measure is the nearest-rank 90th percentile (P90) of the distances in each slice. Additional administrator-selected percentiles, mean, standard deviation, and histograms supplement it. P50 and a separate median are deliberately omitted from the report and are not calculated.

The movement map offers one reference circle for men and one for women. Each circle is centred on the most frequent birthplace among the corresponding observations. Its radius is the arithmetic mean of the non-empty time-slice P90 values in the displayed period; empty slices do not contribute to the mean.

## Consanguinity rate

The displayed consanguinity rate is a population-level indicator of how often blood-related marriages occur in the selected data. For descendants, such marriages connect already related ancestral lines and cause ancestors to recur in a pedigree (pedigree collapse or implex, German: *Ahnenschwund*).

For the selected period, the module calculates:

$$
C = \frac{N_{\mathrm{related\ marriages}}}{N_{\mathrm{marriages}}}
$$

The denominator contains each eligible selected family exactly once when at least one spouse is explicitly selected, both spouses and the marriage fact are visible, and a usable marriage date falls within the selected period. The numerator contains those marriages for which the relationship service finds a blood relationship. The result is displayed as a percentage.

Blood relationships are determined using an available Vesta relationship service or, as a privacy-aware fallback, webtrees relationship paths. The whole visible tree may be searched for this supplementary information even though no records are added to the distance analysis.

## Wright's inbreeding coefficient

The individual inbreeding coefficient \(F\) according to Wright measures the probability that an individual's two alleles at a locus are identical by descent. It is calculated from the common ancestors of the individual's parents:

$$
F = \sum_A \left(\frac{1}{2}\right)^{n_1+n_2+1} \times (1+F_A)
$$

where:

- \(n_1\) and \(n_2\) are the numbers of generations from each parent to the common ancestor \(A\);
- \(F_A\) is the inbreeding coefficient of the common ancestor, often assumed to be zero when it is unknown;
- the contributions are summed over the common ancestors and valid independent ancestral paths.

For a population, a mean inbreeding coefficient is often calculated across many marriages or their offspring:

$$
\overline{F} = \frac{\sum_{i=1}^{N} F_i}{N}
$$

This mean preserves the degree of relatedness represented by every individual \(F_i\). It is therefore different from merely counting whether a marriage has any detected blood relationship.

The module currently calculates neither an individual's Wright coefficient nor the population mean \(\overline{F}\). Its displayed consanguinity rate is the simpler observed proportion of blood-related marriages in the analysed population and period. These measures describe related aspects of consanguinity but are not interchangeable.

## Privacy

All records and facts are accessed with the current visitor's webtrees permissions. Names and relationship results are shown only for visible records. Loading the map may transmit request data to the map provider configured by the administrator.
