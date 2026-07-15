<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\CourtshipRadiusModule\Service;

use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Hartenthaler\Webtrees\Module\CourtshipRadiusModule\Model\CourtshipObservation;
use Hartenthaler\Webtrees\Module\CourtshipRadiusModule\Model\PlacePoint;

final class CartAnalysisService
{
    public function __construct(
        private readonly PlaceResolver $placeResolver,
        private readonly DistanceService $distanceService,
        private readonly BloodRelationshipService $bloodRelationshipService,
    ) {
    }

    /**
     * @param array<string> $xrefs Exact record XREFs from the current tree's session cart.
     * @return array{selected_families:int,selected_individuals:int,marriages:array<int,array{family_xref:string,marriage_year:int,first_name:string,second_name:string,blood_relationship:string|null}>,observations:array<CourtshipObservation>,excluded:array<int,array{family_xref:string,subject_xref:string,reason:string}>}
     */
    public function analyse(Tree $tree, array $xrefs): array
    {
        $selected = array_fill_keys($xrefs, true);
        $families = [];
        $selectedIndividuals = 0;

        foreach ($xrefs as $xref) {
            $record = Registry::gedcomRecordFactory()->make($xref, $tree);
            if ($record instanceof Family && $record->canShow()) {
                $families[$record->xref()] = $record;
            } elseif ($record instanceof Individual && $record->canShow()) {
                $selectedIndividuals++;
            }
        }

        $observations = [];
        $marriages = [];
        $excluded = [];

        foreach ($families as $family) {
            $husband = $family->husband();
            $wife = $family->wife();
            if (!$husband instanceof Individual || !$wife instanceof Individual
                || !$husband->canShow() || !$wife->canShow()) {
                $excluded[] = ['family_xref' => $family->xref(), 'subject_xref' => '', 'reason' => 'missing_or_private_partner'];
                continue;
            }

            if (!isset($selected[$husband->xref()]) && !isset($selected[$wife->xref()])) {
                $excluded[] = ['family_xref' => $family->xref(), 'subject_xref' => '', 'reason' => 'no_selected_partner'];
                continue;
            }

            $marriage = $this->marriageFact($family);
            if ($marriage === null) {
                $excluded[] = ['family_xref' => $family->xref(), 'subject_xref' => '', 'reason' => 'missing_marriage_date'];
                continue;
            }

            $marriageYear = $marriage->date()->gregorianYear();
            $marriageJulianDay = $marriage->date()->julianDay();

            $bloodRelationship = $this->bloodRelationshipService->relationship($husband, $wife);
            $marriages[] = [
                'family_xref'        => $family->xref(),
                'marriage_year'      => $marriageYear,
                'first_name'         => $this->plainName($husband),
                'second_name'        => $this->plainName($wife),
                'blood_relationship' => $bloodRelationship,
            ];

            foreach ([[$husband, $wife], [$wife, $husband]] as [$subject, $partner]) {
                if (!isset($selected[$subject->xref()])) {
                    continue;
                }

                $observation = $this->observation(
                    $family,
                    $subject,
                    $partner,
                    $marriage,
                    $marriageYear,
                    $marriageJulianDay,
                    $bloodRelationship,
                    $excluded,
                );
                if ($observation !== null) {
                    $observations[] = $observation;
                }
            }
        }

        return [
            'selected_families'    => count($families),
            'selected_individuals' => $selectedIndividuals,
            'marriages'            => $marriages,
            'observations'         => $observations,
            'excluded'             => $excluded,
        ];
    }

    /** @param array<int,array{family_xref:string,subject_xref:string,reason:string}> $excluded */
    private function observation(
        Family $family,
        Individual $subject,
        Individual $partner,
        Fact $marriage,
        int $marriageYear,
        int $marriageJulianDay,
        string|null $bloodRelationship,
        array &$excluded,
    ): CourtshipObservation|null {
        $sex = $subject->sex();
        if ($sex !== 'M' && $sex !== 'F') {
            $excluded[] = ['family_xref' => $family->xref(), 'subject_xref' => $subject->xref(), 'reason' => 'unknown_sex'];
            return null;
        }

        $origin = $this->birthPlace($subject);
        if ($origin === null) {
            $excluded[] = ['family_xref' => $family->xref(), 'subject_xref' => $subject->xref(), 'reason' => 'missing_birth_coordinates'];
            return null;
        }

        [$destination, $destinationKind] = $this->destination($partner, $marriage, $marriageJulianDay);
        if (!$destination instanceof PlacePoint) {
            $excluded[] = ['family_xref' => $family->xref(), 'subject_xref' => $subject->xref(), 'reason' => 'missing_destination_coordinates'];
            return null;
        }

        return new CourtshipObservation(
            $family->xref(),
            $subject->xref(),
            $this->plainName($subject),
            $sex,
            $partner->xref(),
            $this->plainName($partner),
            $marriageYear,
            $origin,
            $destination,
            $destinationKind,
            $this->distanceService->between($origin, $destination),
            $bloodRelationship,
        );
    }

    private function marriageFact(Family $family): Fact|null
    {
        foreach ($family->facts(['MARR']) as $fact) {
            if ($fact->date()->isOK()) {
                return $fact;
            }
        }

        return null;
    }

    private function plainName(Individual $individual): string
    {
        return html_entity_decode(strip_tags(str_replace([
            '<q class="wt-nickname">',
            '</q>',
        ], '"', $individual->fullName())), ENT_QUOTES);
    }

    private function birthPlace(Individual $individual): PlacePoint|null
    {
        foreach ($individual->facts(['BIRT']) as $birth) {
            $place = $this->placeResolver->resolve($birth);
            if ($place !== null) {
                return $place;
            }
        }

        return null;
    }

    /** @return array{PlacePoint|null,string} */
    private function destination(Individual $partner, Fact $marriage, int $marriageJulianDay): array
    {
        $residences = [];
        foreach ($partner->facts(['RESI']) as $residence) {
            $date = $residence->date();
            if (!$date->isOK() || !$this->covers($date->qual1, $date->qual2, $date->minimumJulianDay(), $date->maximumJulianDay(), $marriageJulianDay)) {
                continue;
            }

            $distanceToBoundary = min(
                abs($marriageJulianDay - $date->minimumJulianDay()),
                abs($date->maximumJulianDay() - $marriageJulianDay),
            );
            $residences[] = [$distanceToBoundary, $residence];
        }

        usort($residences, static fn (array $left, array $right): int => $left[0] <=> $right[0]);
        foreach ($residences as [, $residence]) {
            $place = $this->placeResolver->resolve($residence);
            if ($place !== null) {
                return [$place, 'partner_residence'];
            }
        }

        $marriagePlace = $this->placeResolver->resolve($marriage);
        if ($marriagePlace !== null) {
            return [$marriagePlace, 'marriage_place'];
        }

        $partnerBirthPlace = $this->birthPlace($partner);
        if ($partnerBirthPlace !== null) {
            return [$partnerBirthPlace, 'partner_birth'];
        }

        return [null, ''];
    }

    private function covers(string $qualifier1, string $qualifier2, int $minimum, int $maximum, int $marriage): bool
    {
        if ($qualifier1 === 'FROM' && $qualifier2 === 'TO') {
            return $minimum <= $marriage && $marriage <= $maximum;
        }

        if ($qualifier1 === 'FROM' && $qualifier2 === '') {
            return $minimum <= $marriage;
        }

        if ($qualifier1 === 'TO') {
            return $marriage <= $maximum;
        }

        return false;
    }
}
