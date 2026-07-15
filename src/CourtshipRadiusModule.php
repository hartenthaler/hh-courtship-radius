<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\CourtshipRadiusModule;

use Fisharebest\Localization\Translation;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleChartInterface;
use Fisharebest\Webtrees\Module\ModuleChartTrait;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleMapProviderInterface;
use Fisharebest\Webtrees\Services\LeafletJsService;
use Fisharebest\Webtrees\Services\ModuleService;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Fisharebest\Webtrees\Http\ViewResponseTrait;
use Hartenthaler\Webtrees\Module\CourtshipRadiusModule\Model\CourtshipObservation;
use Hartenthaler\Webtrees\Module\CourtshipRadiusModule\Service\BloodRelationshipService;
use Hartenthaler\Webtrees\Module\CourtshipRadiusModule\Service\CartAnalysisService;
use Hartenthaler\Webtrees\Module\CourtshipRadiusModule\Service\CsvService;
use Hartenthaler\Webtrees\Module\CourtshipRadiusModule\Service\DistanceService;
use Hartenthaler\Webtrees\Module\CourtshipRadiusModule\Service\PlaceResolver;
use Hartenthaler\Webtrees\Module\CourtshipRadiusModule\Service\ReportService;
use Hartenthaler\Webtrees\Module\CourtshipRadiusModule\Service\StatisticsService;
use Hartenthaler\Webtrees\Module\CourtshipRadiusModule\Service\TimeSliceService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

use function file_exists;
use function redirect;
use function response;

final class CourtshipRadiusModule extends AbstractModule implements ModuleCustomInterface, ModuleChartInterface, ModuleConfigInterface
{
    use ModuleCustomTrait;
    use ModuleChartTrait;
    use ModuleConfigTrait;
    use ViewResponseTrait;

    private const MODULE_NAME = 'hh-courtship-radius';
    private const GITHUB_USER = 'hartenthaler';
    private const DEFAULT_PERCENTILES = '63,90,95.5,99';

    private readonly CartAnalysisService $analysisService;
    private readonly ReportService $reportService;

    public function __construct(
        private readonly ModuleService $moduleService,
        private readonly LeafletJsService $leafletJsService,
    ) {
        $this->analysisService = new CartAnalysisService(
            new PlaceResolver(),
            new DistanceService(),
            new BloodRelationshipService(),
        );
        $this->reportService = new ReportService(new StatisticsService(), new TimeSliceService());
    }

    public function boot(): void
    {
        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');
    }

    public function title(): string
    {
        return I18N::translate('Courtship radius');
    }

    public function description(): string
    {
        return I18N::translate('Analyse the geographic courtship radius of selected families over time.');
    }

    public function chartMenuClass(): string
    {
        return 'menu-chart-courtship-radius';
    }

    public function chartTitle(Individual $individual): string
    {
        return $this->title();
    }

    public function getChartAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();
        Auth::checkComponentAccess($this, ModuleChartInterface::class, $tree, $user);

        $xrefs = $this->cartXrefs($tree->name());
        $analysis = $this->analysisService->analyse($tree, $xrefs);

        $observations = $analysis['observations'];
        $years = array_map(static fn (CourtshipObservation $observation): int => $observation->marriageYear, $observations);
        $defaultFrom = $years === [] ? (int) date('Y') - 400 : min($years);
        $defaultTo   = $years === [] ? (int) date('Y') : max($years);
        [$fromYear, $toYear] = $this->requestedYears($request, $defaultFrom, $defaultTo);

        $percentiles = $this->percentiles();
        $sort = $this->crossTableSort();
        $report = $this->reportService->build($observations, $analysis['marriages'], $fromYear, $toYear, $percentiles, $sort);

        $leafletConfig = null;
        $mapMessage = null;
        try {
            $leafletConfig = $this->leafletJsService->config();
        } catch (Throwable $exception) {
            $mapMessage = $exception->getMessage();
        }

        $chartUrl = route('module', [
            'module' => $this->name(),
            'action' => 'Chart',
            'tree'   => $tree->name(),
        ]);
        $chartQuery = [];
        parse_str((string) parse_url($chartUrl, PHP_URL_QUERY), $chartQuery);

        return $this->viewResponse($this->name() . '::chart', [
            'title'           => $this->title(),
            'tree'            => $tree,
            'module'          => $this->name(),
            'chart_url'       => explode('?', $chartUrl, 2)[0],
            'chart_query'     => $chartQuery,
            'analysis'        => $analysis,
            'report'          => $report,
            'from_year'       => $fromYear,
            'to_year'         => $toYear,
            'percentiles'     => $percentiles,
            'leaflet_config'  => $leafletConfig,
            'map_message'     => $mapMessage,
            'csv_url'         => route('module', [
                'module' => $this->name(),
                'action' => 'Csv',
                'tree'   => $tree->name(),
                'from'   => $fromYear,
                'to'     => $toYear,
            ]),
        ]);
    }

    public function getCsvAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();
        Auth::checkComponentAccess($this, ModuleChartInterface::class, $tree, $user);

        $analysis = $this->analysisService->analyse($tree, $this->cartXrefs($tree->name()));
        $years = array_map(static fn (CourtshipObservation $observation): int => $observation->marriageYear, $analysis['observations']);
        $defaultFrom = $years === [] ? (int) date('Y') - 400 : min($years);
        $defaultTo = $years === [] ? (int) date('Y') : max($years);
        [$fromYear, $toYear] = $this->requestedYears($request, $defaultFrom, $defaultTo);
        $report = $this->reportService->build($analysis['observations'], $analysis['marriages'], $fromYear, $toYear, $this->percentiles(), $this->crossTableSort());

        $stream = fopen('php://temp', 'w+');
        $csvService = new CsvService();
        fwrite($stream, $csvService->separatorDeclaration());
        fwrite($stream, $csvService->row([I18N::translate('Evaluated observations')]));
        fwrite($stream, $csvService->row([
            MoreI18N::xlate('Family'),
            I18N::translate('Person'),
            MoreI18N::xlate('Sex'),
            I18N::translate('Partner'),
            I18N::translate('Marriage year'),
            I18N::translate('Birth place'),
            MoreI18N::xlate('Destination'),
            I18N::translate('Destination source'),
            I18N::translate('Distance (km)'),
            I18N::translate('Blood relationship'),
        ]));
        foreach ($report['observations'] as $observation) {
            fwrite($stream, $csvService->row([
                $observation->familyXref,
                $observation->subjectName . ' (' . $observation->subjectXref . ')',
                $observation->sex,
                $observation->partnerName . ' (' . $observation->partnerXref . ')',
                $observation->marriageYear,
                $observation->origin->name,
                $observation->destination->name,
                $observation->destinationKind,
                number_format($observation->distance, 3, '.', ''),
                $observation->bloodRelationship ?? '',
            ]));
        }

        fwrite($stream, $csvService->row([]));
        fwrite($stream, $csvService->row([I18N::translate('Statistics by period')]));
        $statisticsHeader = [
            I18N::translate('Period'),
            MoreI18N::xlate('Sex'),
            I18N::translate('Persons'),
            I18N::translate('Mean'),
            I18N::translate('Standard deviation'),
        ];
        foreach ($this->percentiles() as $percentile) {
            $statisticsHeader[] = 'P' . $this->formatPercentile($percentile);
        }
        fwrite($stream, $csvService->row($statisticsHeader));
        foreach ($report['series'] as $row) {
            foreach (['M', 'F'] as $sex) {
                $statistics = $row[$sex];
                $csvRow = [
                    $row['label'],
                    $sex,
                    $statistics['count'],
                    $statistics['mean'] === null ? '' : number_format($statistics['mean'], 3, '.', ''),
                    $statistics['standard_deviation'] === null ? '' : number_format($statistics['standard_deviation'], 3, '.', ''),
                ];
                foreach ($statistics['percentiles'] as $value) {
                    $csvRow[] = $value === null ? '' : number_format($value, 3, '.', '');
                }
                fwrite($stream, $csvService->row($csvRow));
            }
        }

        foreach (['M' => I18N::translate('Men'), 'F' => I18N::translate('Women')] as $sex => $label) {
            $table = $report['cross_tables'][$sex];
            fwrite($stream, $csvService->row([]));
            fwrite($stream, $csvService->row([I18N::translate('Cross table') . ' – ' . $label]));
            fwrite($stream, $csvService->row(array_merge([I18N::translate('Birth place')], $table['columns'], [MoreI18N::xlate('Total')])));
            foreach ($table['rows'] as $rowName) {
                $csvRow = [$rowName];
                foreach ($table['columns'] as $columnName) {
                    $csvRow[] = $table['cells'][$rowName][$columnName] ?? 0;
                }
                $csvRow[] = $table['rowTotals'][$rowName];
                fwrite($stream, $csvService->row($csvRow));
            }
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return response("\xEF\xBB\xBF" . $csv)
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="courtship-radius-' . $tree->name() . '-' . $fromYear . '-' . $toYear . '.csv"');
    }

    public function getAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = 'layouts/administration';

        return $this->viewResponse($this->name() . '::settings', [
            'title'             => $this->title(),
            'percentiles'       => implode(',', array_map($this->formatPercentile(...), $this->percentiles())),
            'cross_table_sort'  => $this->crossTableSort(),
        ]);
    }

    public function postAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $percentiles = $this->parsePercentiles((string) ($body['percentiles'] ?? self::DEFAULT_PERCENTILES));
        $sort = (string) ($body['cross_table_sort'] ?? 'frequency');
        if (!in_array($sort, ['frequency', 'alphabetical'], true)) {
            $sort = 'frequency';
        }

        $this->setPreference('PERCENTILES', implode(',', array_map($this->formatPercentile(...), $percentiles)));
        $this->setPreference('CROSS_TABLE_SORT', $sort);
        FlashMessages::addMessage(MoreI18N::xlate('The preferences for the module “%s” have been updated.', $this->title()), 'success');

        return redirect($this->getConfigLink());
    }

    /**
     * @return array{
     *     third_party_services:list<array{
     *         service_id:string,
     *         name:string,
     *         url:string,
     *         country:string,
     *         privacy_url:string,
     *         data:list<string>,
     *         description:string,
     *         group:string
     *     }>,
     *     security_measures:list<string>
     * }
     */
    public function privacyNotices(): array
    {
        $services = [];
        foreach ($this->moduleService->findByInterface(ModuleMapProviderInterface::class) as $provider) {
            $details = $this->mapProviderPrivacy($provider->name());
            $services[] = [
                'service_id'  => 'map-provider-' . $provider->name(),
                'name'        => strip_tags($provider->title()),
                'url'         => $details['url'],
                'country'     => $details['country'],
                'privacy_url' => $details['privacy_url'],
                'data'        => [I18N::translate('IP address, browser data, requested map area')],
                'description' => I18N::translate('This module uses the map provider enabled in webtrees to display places and movement routes.'),
                'group'       => 'map',
            ];
        }

        return ['third_party_services' => $services, 'security_measures' => []];
    }

    public function customModuleAuthorName(): string
    {
        return 'Hermann Hartenthaler';
    }

    public function customModuleVersion(): string
    {
        return trim((string) file_get_contents(__DIR__ . '/../version.txt'));
    }

    public function customModuleLatestVersionUrl(): string
    {
        return 'https://raw.githubusercontent.com/' . self::GITHUB_USER . '/' . self::MODULE_NAME . '/main/version.txt';
    }

    public function customModuleSupportUrl(): string
    {
        return 'https://github.com/' . self::GITHUB_USER . '/' . self::MODULE_NAME;
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . '/../resources/';
    }

    public function customTranslations(string $language): array
    {
        $file = $this->resourcesFolder() . 'lang/' . $language . '.mo';

        return file_exists($file) ? (new Translation($file))->asArray() : [];
    }

    /** @return array<string> */
    private function cartXrefs(string $treeName): array
    {
        $cart = Session::get('cart');
        $cart = is_array($cart) ? $cart : [];

        return array_map('strval', array_keys(is_array($cart[$treeName] ?? null) ? $cart[$treeName] : []));
    }

    /** @return array{int,int} */
    private function requestedYears(ServerRequestInterface $request, int $defaultFrom, int $defaultTo): array
    {
        $query = $request->getQueryParams();
        $from = filter_var($query['from'] ?? null, FILTER_VALIDATE_INT);
        $to = filter_var($query['to'] ?? null, FILTER_VALIDATE_INT);
        $from = $from === false ? $defaultFrom : max(-5000, min(5000, $from));
        $to = $to === false ? $defaultTo : max(-5000, min(5000, $to));

        return $from <= $to ? [$from, $to] : [$to, $from];
    }

    /** @return array<float> */
    private function percentiles(): array
    {
        return $this->parsePercentiles($this->getPreference('PERCENTILES', self::DEFAULT_PERCENTILES));
    }

    /** @return array<float> */
    private function parsePercentiles(string $value): array
    {
        $values = [];
        foreach (preg_split('/[;,\s]+/', $value) ?: [] as $part) {
            if (is_numeric($part)) {
                $number = (float) $part;
                if ($number > 0.0 && $number <= 100.0 && $number !== 50.0) {
                    $values[$this->formatPercentile($number)] = $number;
                }
            }
        }
        $values['90'] = 90.0;
        asort($values, SORT_NUMERIC);

        return array_values($values);
    }

    private function formatPercentile(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function crossTableSort(): string
    {
        $sort = $this->getPreference('CROSS_TABLE_SORT', 'frequency');

        return in_array($sort, ['frequency', 'alphabetical'], true) ? $sort : 'frequency';
    }

    /** @return array{url:string,country:string,privacy_url:string} */
    private function mapProviderPrivacy(string $name): array
    {
        return match ($name) {
            'openstreetmap' => ['url' => 'https://www.openstreetmap.org', 'country' => 'United Kingdom', 'privacy_url' => 'https://osmfoundation.org/wiki/Privacy_Policy'],
            'google-maps'   => ['url' => 'https://maps.google.com', 'country' => 'United States', 'privacy_url' => 'https://policies.google.com/privacy'],
            'bing-maps'     => ['url' => 'https://www.bing.com/maps', 'country' => 'United States', 'privacy_url' => 'https://privacy.microsoft.com/privacystatement'],
            'here-maps'     => ['url' => 'https://www.here.com', 'country' => 'Netherlands', 'privacy_url' => 'https://legal.here.com/en-gb/privacy'],
            'mapbox'        => ['url' => 'https://www.mapbox.com', 'country' => 'United States', 'privacy_url' => 'https://www.mapbox.com/legal/privacy'],
            'esri-maps'     => ['url' => 'https://www.esri.com', 'country' => 'United States', 'privacy_url' => 'https://www.esri.com/en-us/privacy/privacy-statements/privacy-statement'],
            default         => ['url' => '', 'country' => '', 'privacy_url' => ''],
        };
    }
}
