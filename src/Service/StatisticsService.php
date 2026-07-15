<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\CourtshipRadiusModule\Service;

final class StatisticsService
{
    /**
     * @param array<float|int> $values
     * @param array<float>     $percentiles
     * @return array{count:int,mean:float|null,standard_deviation:float|null,percentiles:array<string,float|null>}
     */
    public function summarize(array $values, array $percentiles): array
    {
        $values = array_values(array_map('floatval', $values));
        $percentiles = array_values(array_filter(
            $percentiles,
            static fn (float $percentile): bool => $percentile !== 50.0,
        ));
        sort($values, SORT_NUMERIC);
        $count = count($values);

        $result = [
            'count'              => $count,
            'mean'               => null,
            'standard_deviation' => null,
            'percentiles'        => [],
        ];

        if ($count === 0) {
            foreach ($percentiles as $percentile) {
                $result['percentiles'][$this->percentileKey($percentile)] = null;
            }

            return $result;
        }

        $mean = array_sum($values) / $count;
        $sumOfSquaredDifferences = 0.0;
        foreach ($values as $value) {
            $sumOfSquaredDifferences += ($value - $mean) ** 2;
        }

        $result['mean']               = $mean;
        $result['standard_deviation'] = sqrt($sumOfSquaredDifferences / $count);

        foreach ($percentiles as $percentile) {
            $result['percentiles'][$this->percentileKey($percentile)] = $this->percentile($values, $percentile);
        }

        return $result;
    }

    /**
     * Nearest-rank percentile: the smallest value for which at least p percent
     * of the observations are less than or equal to it.
     *
     * @param array<float|int> $sortedValues
     */
    public function percentile(array $sortedValues, float $percentile): float|null
    {
        if ($sortedValues === []) {
            return null;
        }

        $values = array_values(array_map('floatval', $sortedValues));
        sort($values, SORT_NUMERIC);

        $percentile = max(0.0, min(100.0, $percentile));
        if ($percentile === 0.0) {
            return $values[0];
        }

        $rank = (int) ceil($percentile / 100 * count($values));

        return $values[max(0, $rank - 1)];
    }

    /**
     * @param array<float|int> $values
     * @return array<int,array{from:float,to:float|null,count:int}>
     */
    public function histogram(array $values, float|null $sharedMaximum = null): array
    {
        $buckets = [];

        for ($from = 0; $from < 10; $from++) {
            $buckets[] = ['from' => (float) $from, 'to' => (float) ($from + 1), 'count' => 0];
        }

        $maximum = $sharedMaximum ?? ($values === [] ? 10.0 : (float) max($values));
        $maximum = max(10.0, $maximum);
        $upperLimit = ((int) floor($maximum / 5) + 1) * 5;
        for ($from = 10; $from < $upperLimit; $from += 5) {
            $buckets[] = ['from' => (float) $from, 'to' => (float) ($from + 5), 'count' => 0];
        }

        $buckets[] = ['from' => (float) $upperLimit, 'to' => null, 'count' => 0];

        foreach ($values as $value) {
            $value = (float) $value;
            foreach ($buckets as &$bucket) {
                if ($value >= $bucket['from'] && ($bucket['to'] === null || $value < $bucket['to'])) {
                    $bucket['count']++;
                    break;
                }
            }
            unset($bucket);
        }

        return $buckets;
    }

    private function percentileKey(float $percentile): string
    {
        return rtrim(rtrim(number_format($percentile, 2, '.', ''), '0'), '.');
    }
}
