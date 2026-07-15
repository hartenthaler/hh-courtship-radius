<?php

declare(strict_types=1);

use Hartenthaler\Webtrees\Module\CourtshipRadiusModule\Model\PlacePoint;
use Hartenthaler\Webtrees\Module\CourtshipRadiusModule\Model\CourtshipObservation;
use Hartenthaler\Webtrees\Module\CourtshipRadiusModule\Service\CsvService;
use Hartenthaler\Webtrees\Module\CourtshipRadiusModule\Service\DistanceService;
use Hartenthaler\Webtrees\Module\CourtshipRadiusModule\Service\ReportService;
use Hartenthaler\Webtrees\Module\CourtshipRadiusModule\Service\StatisticsService;
use Hartenthaler\Webtrees\Module\CourtshipRadiusModule\Service\TimeSliceService;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Hartenthaler\\Webtrees\\Module\\CourtshipRadiusModule\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$distance = (new DistanceService())->between(
    new PlacePoint('Munich', 48.137154, 11.576124, 'test'),
    new PlacePoint('Berlin', 52.520008, 13.404954, 'test'),
);
$assert(abs($distance - 504.2) < 2.0, 'Haversine distance Munich–Berlin');
$assert((new DistanceService())->between(
    new PlacePoint('Same', 1.0, 2.0, 'test'),
    new PlacePoint('Same', 1.0, 2.0, 'test'),
) === 0.0, 'Identical places have distance zero');

$statistics = new StatisticsService();
$values = [1, 2, 3, 4, 100];
$assert($statistics->percentile($values, 90.0) === 100.0, 'Nearest-rank P90');
$summary = $statistics->summarize($values, [50.0, 63.0, 90.0]);
$assert($summary['count'] === 5, 'Statistics count');
$assert(!array_key_exists('median', $summary), 'Statistics do not calculate a median');
$assert(!array_key_exists('50', $summary['percentiles']), 'Statistics do not calculate P50');
$assert($summary['percentiles']['90'] === 100.0, 'Statistics P90');
$maleHistogram = $statistics->histogram([12.0], 17.0);
$femaleHistogram = $statistics->histogram([17.0], 17.0);
$assert(array_column($maleHistogram, 'from') === array_column($femaleHistogram, 'from'), 'Shared histogram buckets');
$assert(array_sum(array_column($maleHistogram, 'count')) === 1, 'Histogram includes the shared range');

$csv = new CsvService();
$assert($csv->separatorDeclaration() === "sep=;\r\n", 'CSV declares the Excel separator');
$assert(
    $csv->row(['Mengen; Sigmaringen, DEU', 'Name "Nickname"']) === "\"Mengen; Sigmaringen, DEU\";\"Name \"\"Nickname\"\"\"\r\n",
    'CSV preserves commas, semicolons, and quotes inside fields',
);

$plan = (new TimeSliceService())->plan(1600, 1969);
$assert($plan['width'] === 50, '370 years use 50-year slices');
$assert($plan['count'] === 8, '370 years use eight slices');
$assert($plan['rounded_from'] === 1600 && $plan['rounded_to'] === 1999, 'Slice boundaries are rounded');
$assert((new TimeSliceService())->plan(1800, 1801)['count'] === 5, 'Short ranges still use five slices');
$assert((new TimeSliceService())->plan(-5000, 5000)['count'] >= 5 && (new TimeSliceService())->plan(-5000, 5000)['count'] <= 10, 'Maximum range uses five to ten slices');

$origin = new PlacePoint('A', 48.0, 11.0, 'test');
$destination = new PlacePoint('B', 48.1, 11.1, 'test');
$report = (new ReportService($statistics, new TimeSliceService()))->build([
    new CourtshipObservation('F1', 'I1', 'Man', 'M', 'I2', 'Woman', 1800, $origin, $destination, 'marriage_place', 10.0, null),
    new CourtshipObservation('F1', 'I2', 'Woman', 'F', 'I1', 'Man', 1800, $destination, $origin, 'partner_birth', 12.0, null),
    new CourtshipObservation('F2', 'I3', 'Other man', 'M', 'I4', 'Other woman', 1825, $origin, $destination, 'marriage_place', 30.0, null),
], [
    ['family_xref' => 'F1', 'marriage_year' => 1800, 'first_name' => 'Man', 'second_name' => 'Woman', 'blood_relationship' => 'second cousins'],
    ['family_xref' => 'F2', 'marriage_year' => 1810, 'first_name' => 'Other man', 'second_name' => 'Other woman', 'blood_relationship' => null],
    ['family_xref' => 'F3', 'marriage_year' => 1900, 'first_name' => 'Later man', 'second_name' => 'Later woman', 'blood_relationship' => 'first cousins'],
], 1750, 1849, [63.0, 90.0], 'frequency');
$assert($report['totals']['M']['percentiles']['90'] === 30.0, 'Male P90');
$assert($report['totals']['F']['percentiles']['90'] === 12.0, 'Female P90');
$assert($report['cross_tables']['M']['cells']['A']['B'] === 2, 'Male cross table');
$assert($report['map']['reference_circles']['F']['radius'] === 12.0, 'Map reference radius');
$assert($report['map']['reference_circles']['M']['radius'] === 20.0, 'Map reference radius averages time-slice P90 values');
$assert($report['map']['reference_circles']['M']['center']['name'] === 'A', 'Map reference centre');
$assert($report['consanguinity']['related_marriages'] === 1, 'Related marriage count');
$assert($report['consanguinity']['total_marriages'] === 2, 'Total marriage count');
$assert($report['consanguinity']['rate'] === 0.5, 'Consanguinity rate');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, "All tests passed.\n");
