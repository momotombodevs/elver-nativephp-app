<native:top-bar title="Elver" />

<native:scroll-view ref="climate-summary-screen" class="w-full h-full bg-theme-background">
    <native:column class="w-full p-4 gap-5">
        @if ($locationId === null)
            <native:column ref="summary-empty-state" class="w-full rounded-lg bg-theme-surface p-5 gap-3">
                <native:icon name="location.slash" :size="32" class="text-theme-primary" />
                <native:text class="text-xl font-bold text-theme-on-surface">Agrega una ubicación</native:text>
                <native:text class="text-sm text-theme-on-surface-variant">Consulta el clima de tu zona.</native:text>
                <native:button ref="summary-add-location" class="w-full" size="lg" @navigate="'/locations'" a11y-label="Agregar ubicación">Agregar ubicación</native:button>
            </native:column>
        @else
            <native:row class="w-full items-center justify-between">
                <native:column class="flex-1 gap-1">
                    <native:text class="text-xs font-semibold text-theme-on-surface-variant">UBICACIÓN PRINCIPAL</native:text>
                    <native:text ref="summary-location-name" class="text-2xl font-bold text-theme-on-surface">{{ $this->locationName }}</native:text>
                    @if ($fetchedAt !== null)
                        <native:text class="text-xs text-theme-on-surface-variant">Actualizado {{ $fetchedAt }}</native:text>
                    @endif
                </native:column>
                <native:button ref="refresh-summary" variant="secondary" :disabled="$loading" :loading="$loading" @tap="refreshForecast" a11y-label="Actualizar resumen climático">{{ $loading ? 'Actualizando…' : 'Actualizar' }}</native:button>
            </native:row>

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

                <native:column class="w-full rounded-lg bg-theme-primary/10 p-5 gap-4">
                    <native:text class="text-sm font-semibold text-theme-primary">Condiciones actuales</native:text>
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

                <native:column class="w-full gap-2">
                    <native:row class="w-full items-center justify-between">
                        <native:column class="gap-1">
                            <native:text class="text-lg font-bold text-theme-on-surface">Próximas 24 horas</native:text>
                            <native:text class="text-sm text-theme-on-surface-variant">Temperatura · °C</native:text>
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
            @elseif ($loading)
                <native:column ref="summary-loading-state" native:poll="2s" class="w-full rounded-lg bg-theme-surface p-5 gap-3">
                    <native:row class="w-full items-center gap-3">
                        <native:activity-indicator />
                        <native:text class="text-base font-semibold text-theme-on-surface">Consultando el clima</native:text>
                    </native:row>
                    <native:text class="text-sm text-theme-on-surface-variant">Cargando el pronóstico de {{ $this->locationName }}.</native:text>
                </native:column>
            @endif
        @endif
    </native:column>
</native:scroll-view>
