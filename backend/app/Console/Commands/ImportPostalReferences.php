<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ImportPostalReferences extends Command
{
    protected $signature = 'reference:import-postal {file : Department of Posts directory CSV}';

    protected $description = 'Replace Indian postal reference entries from an official directory snapshot';

    private const FIELDS = [
        'pincode' => 'pin_code',
        'officename' => 'office',
        'district' => 'district',
        'statename' => 'state_or_union_territory',
    ];

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path) || ! is_readable($path) || ($csv = fopen($path, 'rb')) === false) {
            $this->error('The postal directory file cannot be opened.');

            return self::FAILURE;
        }

        try {
            $columns = [];
            foreach (fgetcsv($csv, escape: '') ?: [] as $index => $header) {
                $name = strtolower(trim(ltrim((string) $header, "\xEF\xBB\xBF")));
                $columns[$name] = $index;
            }
            if (array_diff_key(self::FIELDS, $columns) !== []) {
                throw new InvalidArgumentException('The postal directory header is incomplete.');
            }

            DB::transaction(function () use ($csv, $columns): void {
                DB::table('postal_reference_entries')->delete();
                $batch = [];
                $count = 0;
                while (($row = fgetcsv($csv, escape: '')) !== false) {
                    if ($row === [null]) {
                        continue;
                    }
                    $entry = [];
                    foreach (self::FIELDS as $field => $target) {
                        $entry[$target] = trim((string) ($row[$columns[$field]] ?? ''));
                    }
                    if (! preg_match('/^[0-9]{6}$/D', $entry['pin_code'])
                        || $entry['office'] === '' || mb_strlen($entry['office']) > 160
                        || $entry['district'] === '' || mb_strlen($entry['district']) > 100
                        || $entry['state_or_union_territory'] === '' || mb_strlen($entry['state_or_union_territory']) > 100) {
                        throw new InvalidArgumentException('Invalid postal directory record at row '.($count + 2).'.');
                    }
                    $batch[] = $entry;
                    $count++;
                    if (count($batch) === 200) {
                        DB::table('postal_reference_entries')->insertOrIgnore($batch);
                        $batch = [];
                    }
                }
                if ($count === 0 || ! feof($csv)) {
                    throw new InvalidArgumentException('The postal directory could not be read completely.');
                }
                if ($batch !== []) {
                    DB::table('postal_reference_entries')->insertOrIgnore($batch);
                }
            });
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            fclose($csv);
        }

        $this->info('Postal directory imported.');

        return self::SUCCESS;
    }
}
