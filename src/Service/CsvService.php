<?php

declare(strict_types=1);

namespace Hartenthaler\Webtrees\Module\CourtshipRadiusModule\Service;

final class CsvService
{
    public function separatorDeclaration(): string
    {
        return "sep=;\r\n";
    }

    /** @param array<mixed> $fields */
    public function row(array $fields): string
    {
        if ($fields === []) {
            return "\r\n";
        }

        $quoted = array_map(
            static fn (mixed $field): string => '"' . str_replace('"', '""', (string) ($field ?? '')) . '"',
            $fields,
        );

        return implode(';', $quoted) . "\r\n";
    }
}
