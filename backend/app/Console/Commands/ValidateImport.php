<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ValidateImport extends Command
{
    protected $signature = 'data:validate {file : JSON-fil med kunder/køretøjer}';
    protected $description = 'Validerer en dataeksport uden at skrive til databasen';

    public function handle(): int
    {
        $path = $this->argument('file');
        if (!is_readable($path)) {
            $this->error('Filen kan ikke læses.');
            return self::FAILURE;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            $this->error('Filen er ikke gyldig JSON.');
            return self::FAILURE;
        }
        $rows = $data['vehicles'] ?? $data;
        if (!is_array($rows)) {
            $this->error('Forventede et array eller feltet vehicles.');
            return self::FAILURE;
        }
        $plates = [];
        $errors = 0;
        foreach ($rows as $index => $row) {
            $plate = strtoupper(preg_replace('/[^A-ZÆØÅ0-9]/u', '', (string) ($row['registration'] ?? $row['plate'] ?? '')));
            if ($plate === '' || !isset($row['customerName']) && !isset($row['customer'])) {
                $this->warn('Række '.($index + 1).' mangler registrering eller kunde.');
                $errors++;
            }
            if ($plate !== '' && isset($plates[$plate])) {
                $this->warn('Dublet: '.$plate.' (række '.($index + 1).').');
                $errors++;
            }
            if ($plate !== '') $plates[$plate] = true;
        }
        $this->info(count($rows).' rækker kontrolleret.');
        $this->info($errors === 0 ? 'Ingen fejl fundet. Der er ikke skrevet data.' : $errors.' fejl fundet. Der er ikke skrevet data.');
        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
