<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EraSeeder extends Seeder
{
    public function run(): void
    {
        $rows = json_decode(file_get_contents(database_path('data/eras.json')), true);
        DB::table('era')->insert(array_map(fn ($r) => [
            'era_key'   => $r['era_key'],
            'era_order' => $r['era_order'],
            'name'      => $r['name'],
        ], $rows));
    }
}
