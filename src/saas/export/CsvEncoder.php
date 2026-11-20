<?php

declare(strict_types=1);

namespace Z0x\WebmanStarter\saas\export;

use RuntimeException;

final class CsvEncoder
{
    /**
     * @param list<string> $header Fixed column names supplied by the server.
     * @param iterable<list<int|string|null>> $rows
     * @return array{body:string,rowCount:int}
     */
    public function encode(array $header, iterable $rows): array
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Unable to open CSV buffer.');
        }

        try {
            $this->write($stream, $header);
            $count = 0;
            foreach ($rows as $row) {
                $this->write($stream, array_map(self::cell(...), $row));
                $count++;
            }
            if (!rewind($stream) || ($body = stream_get_contents($stream)) === false) {
                throw new RuntimeException('Unable to read CSV buffer.');
            }

            return ['body' => $body, 'rowCount' => $count];
        } finally {
            fclose($stream);
        }
    }

    private static function cell(int|string|null $value): int|string
    {
        if ($value === null) {
            return '';
        }
        // Spreadsheet importers may skip leading whitespace, controls or a UTF-8 BOM before a formula.
        if (is_string($value) && preg_match('/\A(?:[\t\r\n]|[\p{Z}\p{Cc}\p{Cf}]*[=+@\-\x{FF1D}\x{FF0B}\x{FF20}\x{FF0D}])/u', $value) === 1) {
            return "'" . $value;
        }

        return $value;
    }

    /** @param resource $stream @param list<int|string> $row */
    private function write($stream, array $row): void
    {
        if (fputcsv($stream, $row, ',', '"', '', "\r\n") === false) {
            throw new RuntimeException('Unable to write CSV row.');
        }
    }
}
