<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportVehicles extends Command
{
    protected $signature = 'data:import-vehicles {file : JSON-fil} {--write : Skriv efter eksplicit bekræftelse}';
    protected $description = 'Forhåndsviser eller importerer validerede kunde/køretøjsdata';

    public function handle(): int
    {
        $data = json_decode((string) @file_get_contents($this->argument('file')), true);
        $rows = is_array($data) ? ($data['vehicles'] ?? $data) : null;
        if (!is_array($rows)) { $this->error('Ugyldig JSON eller manglende vehicles-array.'); return self::FAILURE; }
        $valid = [];
        foreach ($rows as $index => $row) {
            $plate = strtoupper(preg_replace('/[^A-ZÆØÅ0-9]/u', '', (string) ($row['registration'] ?? $row['plate'] ?? '')));
            $name = trim((string) ($row['customerName'] ?? $row['customer'] ?? ''));
            if ($plate === '' || $name === '') { $this->warn('Springer række '.($index + 1).' over.'); continue; }
            $valid[$plate] = ['plate' => $plate, 'name' => $name, 'type' => ($row['customerType'] ?? 'private') === 'business' ? 'business' : 'private', 'make' => $row['make'] ?? null, 'model' => $row['model'] ?? null];
        }
        $this->info(count($valid).' unikke rækker er klar.');
        if (!$this->option('write')) { $this->comment('Forhåndsvisning: ingen data er skrevet. Brug --write efter godkendelse.'); return self::SUCCESS; }
        if (!$this->confirm('Skriv disse data til den lokale MySQL-database?', false)) return self::SUCCESS;
        DB::transaction(function () use ($valid): void {
            foreach ($valid as $item) {
                $customerId = DB::table('customers')->where('display_name', $item['name'])->value('id');
                if (!$customerId) $customerId = DB::table('customers')->insertGetId(['display_name' => $item['name'], 'customer_type' => $item['type'], 'created_at' => now(), 'updated_at' => now()]);
                DB::table('vehicles')->updateOrInsert(['registration_normalized' => $item['plate']], ['customer_id' => $customerId, 'make' => $item['make'], 'model' => $item['model'], 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $this->info('Import gennemført i én transaktion.');
        return self::SUCCESS;
    }
}
