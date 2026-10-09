<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach ([
            'appearance_mode' => 'system',
            'app_locale' => 'es-NI',
            'weather_units' => 'metric',
            'notifications_enabled' => '1',
            'notification_critical' => '1',
            'notification_recommendations' => '1',
            'notification_rapid_changes' => '1',
            'notification_daily_summary' => '0',
        ] as $key => $value) {
            DB::table('app_settings')->insertOrIgnore([
                'key' => $key,
                'value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('app_settings')->whereIn('key', [
            'appearance_mode',
            'app_locale',
            'weather_units',
            'notifications_enabled',
            'notification_critical',
            'notification_recommendations',
            'notification_rapid_changes',
            'notification_daily_summary',
        ])->delete();
    }
};
