<?php

namespace App\Game\Definition;

use Illuminate\Support\Facades\DB;

// 游戏数据版本递增:V3.1.N → V3.1.(N+1)
class GameDataVersion
{
    public static function bump(string $note, string $by): string
    {
        $latest = DB::table('game_data_versions')->orderByDesc('id')->value('version') ?? 'V3.1.0';
        $parts = explode('.', ltrim($latest, 'V'));
        $patch = (int) ($parts[2] ?? 0) + 1;
        $next = 'V' . ($parts[0] ?? '3') . '.' . ($parts[1] ?? '1') . '.' . $patch;
        DB::table('game_data_versions')->insert([
            'version' => $next, 'deployed_at' => now(), 'deployed_by' => $by, 'notes' => $note,
        ]);
        return $next;
    }
}
