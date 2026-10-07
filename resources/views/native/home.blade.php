<native:top-bar title="Resumen de hoy" :subtitle="$fetchedAt !== null ? 'Actualizado '.$fetchedAt : null" display-mode="inline">
    @if ($locationId !== null)
        <native:top-bar-action
            id="refresh-forecast"
            label="Actualizar"
            icon="refresh"
            @tap="refreshForecast"
        />
    @endif
</native:top-bar>

<native:scroll-view ref="climate-summary-screen" class="w-full h-full bg-theme-background">
    <native:column class="w-full p-4 gap-5">
        @if ($locationId === null)
            <native:column ref="summary-empty-state" class="w-full rounded-lg bg-theme-surface p-5 gap-3">
                <native:icon name="location.slash" :size="32" class="text-theme-primary" />
                <native:text class="text-xl font-bold text-theme-on-surface">Sin ubicación</native:text>
                <native:text class="text-base font-semibold text-theme-on-surface">Agrega una ubicación</native:text>
                <native:text class="text-sm text-theme-on-surface-variant">Consulta el clima de tu zona.</native:text>
                <native:button ref="summary-add-location" class="w-full" size="lg" @navigate="'/locations'" a11y-label="Agregar ubicación">Agregar ubicación</native:button>
            </native:column>
        @else
            <native:select
                ref="summary-location"
                label="Ubicación"
                :options="$this->locationOptions"
                native:model="locationChoice"
                a11y-label="Cambiar ubicación del resumen"
            />

            @if ($error !== null)
                <native:column ref="summary-error" class="w-full rounded-md border border-theme-destructive p-3 gap-2">
                    <native:text class="text-sm text-theme-destructive">{{ $error }}</native:text>
                    <native:button class="w-full" variant="secondary" @tap="refreshForecast">Reintentar</native:button>
                </native:column>
            @endif

            @if ($stale)
                <native:text ref="summary-stale-notice" class="w-full rounded-md bg-theme-accent/15 p-3 text-sm font-bold text-theme-accent">Sin conexión · último dato guardado</native:text>
            @endif

            @if ($forecast !== [])
                @if ($loading)
                    <native:row ref="summary-loading" native:poll="2s" class="w-full items-center gap-2">
                        <native:activity-indicator />
                        <native:text class="text-sm text-theme-on-surface-variant">Actualizando…</native:text>
                    </native:row>
                @endif

                <native:column ref="summary-current-conditions" class="w-full rounded-lg bg-theme-surface p-4 gap-3">
                    <native:text class="text-sm font-semibold text-theme-primary">Condiciones actuales</native:text>
                    <native:text class="text-xs text-theme-on-surface-variant">Ahora en {{ $this->locationName }}</native:text>
                    @foreach (array_chunk($this->currentConditions, 2) as $conditionRow)
                        <native:row class="w-full gap-3">
                            @foreach ($conditionRow as $condition)
                                <native:column class="flex-1 gap-1">
                                    <native:text class="text-xs text-theme-on-surface-variant">{{ $condition['label'] }}</native:text>
                                    <native:text class="text-lg font-bold text-theme-on-surface">{{ $condition['value'] }} {{ $condition['unit'] }}</native:text>
                                </native:column>
                            @endforeach
                        </native:row>
                    @endforeach
                </native:column>

                <native:column ref="summary-rain" class="w-full rounded-lg bg-theme-surface p-4 gap-2">
                    <native:text class="text-base font-bold text-theme-on-surface">Lluvia · próximas 24 horas</native:text>
                    @if ($this->next24HoursRain['noData'])
                        <native:text class="text-sm text-theme-on-surface-variant">Sin datos de lluvia para este periodo.</native:text>
                    @else
                        <native:text class="text-2xl font-bold text-theme-accent">
                            {{ number_format($this->next24HoursRain['total'], 1, ',', '.') }} {{ $this->rainUnit }}
                        </native:text>
                        @if ($this->next24HoursRain['partial'])
                            <native:text ref="summary-rain-partial" class="text-sm text-theme-on-surface-variant">
                                Dato parcial · {{ $this->next24HoursRain['reportedHours'] }}/{{ $this->next24HoursRain['expectedHours'] }} horas
                            </native:text>
                        @elseif (! $this->next24HoursRain['hasRain'])
                            <native:text ref="summary-rain-none" class="text-sm text-theme-on-surface-variant">No se espera lluvia.</native:text>
                        @endif
                        @if ($this->next24HoursRain['periods'] !== [])
                            <native:text ref="summary-rain-periods" class="text-sm text-theme-on-surface-variant">
                                Horas con lluvia: {{ implode(' · ', $this->next24HoursRain['periods']) }}
                            </native:text>
                        @endif
                    @endif
                </native:column>

                <native:column class="w-full gap-2">
                    <native:row class="w-full items-center justify-between">
                        <native:column class="gap-1">
                            <native:text class="text-lg font-bold text-theme-on-surface">Próximas 24 horas</native:text>
                            <native:text class="text-sm text-theme-on-surface-variant">Temperatura · {{ $this->temperatureYAxis['title'] }}</native:text>
                        </native:column>
                        <native:button variant="secondary" @navigate="'/explorer'">Explorar</native:button>
                    </native:row>
                    <native:line-chart
                        ref="summary-temperature-chart"
                        class="w-full h-64"
                        :series="$this->temperatureSeries"
                        locale="es-NI"
                        :x-axis="['type' => 'datetime', 'dateFormat' => 'time', 'timezone' => $forecast['timezone'] ?? 'UTC']"
                        :y-axis="$this->temperatureYAxis"
                        :legend="['visible' => false]"
                        :style="['line' => ['width' => 3, 'interpolation' => 'smooth'], 'points' => ['visible' => false], 'axis' => ['labelCount' => 5]]"
                        empty-label="Sin datos para las próximas 24 horas"
                        a11y-label="Temperatura prevista para las próximas 24 horas en {{ $this->locationName }}"
                    />
                </native:column>

                @if ($this->dailyForecast !== [])
                    <native:column class="w-full gap-2">
                        <native:text class="text-lg font-bold text-theme-on-surface">Próximos días</native:text>
                        <native:scroll-view horizontal class="w-full">
                            <native:row class="gap-3">
                                @foreach ($this->dailyForecast as $day)
                                    <native:column class="w-32 rounded-lg bg-theme-surface p-3 gap-1">
                                        <native:text class="text-sm font-semibold text-theme-on-surface">{{ $day['date'] }}</native:text>
                                        <native:text class="text-sm text-theme-on-surface-variant">Máx. {{ $day['maximum'] }}</native:text>
                                        <native:text class="text-sm text-theme-on-surface-variant">Mín. {{ $day['minimum'] }}</native:text>
                                        <native:text class="text-sm text-theme-accent">{{ $day['uv'] }}</native:text>
                                    </native:column>
                                @endforeach
                            </native:row>
                        </native:scroll-view>
                    </native:column>
                @endif

                @if ($this->currentWeatherDetails !== [])
                    <native:column ref="summary-weather-details" class="w-full rounded-lg bg-theme-surface p-4 gap-3">
                        <native:row class="w-full items-center justify-between">
                            <native:text class="text-base font-bold text-theme-on-surface">Más datos</native:text>
                            <native:button size="sm" variant="secondary" @tap="openMetricHelp">¿Qué significa?</native:button>
                        </native:row>
                        @foreach (array_chunk($this->currentWeatherDetails, 2) as $detailRow)
                            <native:row class="w-full gap-3">
                                @foreach ($detailRow as $detail)
                                    <native:column class="flex-1 gap-1">
                                        <native:text class="text-xs text-theme-on-surface-variant">{{ $detail['label'] }}</native:text>
                                        <native:text class="text-base font-semibold text-theme-on-surface">{{ $detail['value'] }} {{ $detail['unit'] }}</native:text>
                                    </native:column>
                                @endforeach
                            </native:row>
                        @endforeach
                    </native:column>
                @endif
            @elseif ($loading)
                <native:column ref="summary-loading-state" native:poll="2s" class="w-full rounded-lg bg-theme-surface p-5 gap-3">
                    <native:row class="w-full items-center gap-3">
                        <native:activity-indicator />
                        <native:text class="text-base font-semibold text-theme-on-surface">Cargando clima…</native:text>
                    </native:row>
                </native:column>
            @endif
        @endif
    </native:column>
</native:scroll-view>

@if ($showMetricHelp)
<native:bottom-sheet :visible="$showMetricHelp" detents="medium" @dismiss="dismissMetricHelp" a11y-label="Ayuda sobre las métricas del clima">
    <native:column class="w-full bg-theme-surface p-5 gap-4">
        <native:text class="text-xl font-bold text-theme-on-surface">Cómo leer el clima</native:text>
        <native:column class="gap-1">
            <native:text class="text-base font-semibold text-theme-on-surface">Lluvia (mm)</native:text>
            <native:text class="text-sm text-theme-on-surface-variant">Es la cantidad de agua prevista. 1 mm equivale a 1 litro por metro cuadrado.</native:text>
        </native:column>
        <native:column class="gap-1">
            <native:text class="text-base font-semibold text-theme-on-surface">Índice UV</native:text>
            <native:text class="text-sm text-theme-on-surface-variant">Indica la intensidad del sol. Mientras más alto, más protección conviene usar.</native:text>
        </native:column>
        <native:column class="gap-1">
            <native:text class="text-base font-semibold text-theme-on-surface">Humedad y sensación térmica</native:text>
            <native:text class="text-sm text-theme-on-surface-variant">La humedad es el agua en el aire. La sensación térmica combina temperatura, viento y humedad.</native:text>
        </native:column>
        <native:button class="w-full" @tap="dismissMetricHelp">Entendido</native:button>
    </native:column>
</native:bottom-sheet>
@endif
