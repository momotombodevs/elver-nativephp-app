<?php

namespace App\Models;

use App\Domain\AgroClima\Enums\AlertState;
use App\Domain\AgroClima\Enums\ThresholdOperator;
use App\Domain\AgroClima\Enums\WeatherMetric;
use Database\Factories\ClimateAlertFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClimateAlert extends Model
{
    /** @use HasFactory<ClimateAlertFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'location_id',
        'metric',
        'operator',
        'threshold',
        'enabled',
        'last_state',
        'last_evaluated_at',
        'last_triggered_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'enabled' => true,
    ];

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return array<string, string|class-string> */
    protected function casts(): array
    {
        return [
            'metric' => WeatherMetric::class,
            'operator' => ThresholdOperator::class,
            'threshold' => 'decimal:2',
            'enabled' => 'boolean',
            'last_state' => AlertState::class,
            'last_evaluated_at' => 'immutable_datetime',
            'last_triggered_at' => 'immutable_datetime',
        ];
    }
}
