<native:top-bar title="Gráficas" />

<native:scroll-view ref="chart-explorer-screen" class="w-full h-full bg-theme-background">
    <native:column class="w-full p-4 gap-4">
        @if ($this->locationOptions === [])
            <native:column ref="explorer-empty-state" class="w-full rounded-lg bg-theme-surface p-5 gap-3">
                <native:icon name="chart.xyaxis.line" :size="32" class="text-theme-primary" />
                <native:text class="text-xl font-bold text-theme-on-surface">Sin datos</native:text>
                <native:text class="text-sm text-theme-on-surface-variant">Agrega una ubicación para ver el pronóstico.</native:text>
                <native:button class="w-full" size="lg" @navigate="'/locations'">Agregar ubicación</native:button>
            </native:column>
        @else
            <native:select ref="explorer-location" label="Ubicación" :options="$this->locationOptions" native:model="locationChoice" a11y-label="Ubicación de la serie climática" />
            <native:row class="w-full gap-3">
                <native:select ref="explorer-metric" class="flex-1" label="Variable" :options="$this->metricOptions" native:model="metricChoice" a11y-label="Variable climática" />
                <native:select ref="explorer-range" class="flex-1" label="Periodo" :options="['24 horas', '7 días']" native:model="rangeChoice" a11y-label="Periodo de la gráfica" />
            </native:row>

            @if ($stale)
                <native:text ref="explorer-stale-notice" class="text-sm font-semibold text-theme-accent">Sin conexión · datos guardados</native:text>
            @endif

            @if ($error !== null)
                <native:column ref="explorer-error" class="w-full rounded-md border border-theme-destructive p-3 gap-2">
                    <native:text class="text-sm text-theme-destructive">{{ $error }}</native:text>
                    <native:button class="w-full" variant="secondary" @tap="refreshSeries">Reintentar</native:button>
                </native:column>
            @endif

            @if ($series !== [])
                <native:row class="w-full items-end justify-between">
                    <native:column class="gap-1">
                        <native:text class="text-xl font-bold text-theme-on-surface">{{ $metricChoice }}</native:text>
                        <native:text class="text-sm text-theme-on-surface-variant">{{ $rangeChoice }}</native:text>
                    </native:column>
                    <native:button ref="refresh-series" variant="secondary" :disabled="$loading" :loading="$loading" @tap="refreshSeries" a11y-label="Actualizar gráfica">{{ $loading ? 'Actualizando…' : 'Actualizar' }}</native:button>
                </native:row>

                @if ($loading)
                    <native:row ref="explorer-loading" native:poll="2s" class="w-full items-center gap-2">
                        <native:activity-indicator />
                        <native:text class="text-sm text-theme-on-surface-variant">Actualizando datos…</native:text>
                    </native:row>
                @endif

                @if ($this->chartKind === 'bar')
                    <native:bar-chart
                        ref="climate-bar-chart"
                        class="w-full h-64"
                        :series="$series"
                        locale="es-NI"
                        :x-axis="$this->chartXAxis"
                        :y-axis="$this->chartYAxis"
                        :legend="['visible' => false]"
                        :style="$this->chartStyle"
                        :interaction="$this->chartInteraction"
                        _select="pointSelected"
                        empty-label="Sin datos para este periodo"
                        a11y-label="{{ $this->chartDescription }}"
                    />
                @elseif ($this->chartKind === 'area')
                    <native:area-chart
                        ref="climate-area-chart"
                        class="w-full h-64"
                        :series="$series"
                        locale="es-NI"
                        :x-axis="$this->chartXAxis"
                        :y-axis="$this->chartYAxis"
                        :legend="['visible' => false]"
                        :style="$this->chartStyle"
                        :interaction="$this->chartInteraction"
                        _select="pointSelected"
                        empty-label="Sin datos para este periodo"
                        a11y-label="{{ $this->chartDescription }}"
                    />
                @else
                    <native:line-chart
                        ref="climate-line-chart"
                        class="w-full h-64"
                        :series="$series"
                        locale="es-NI"
                        :x-axis="$this->chartXAxis"
                        :y-axis="$this->chartYAxis"
                        :legend="['visible' => false]"
                        :style="$this->chartStyle"
                        :interaction="$this->chartInteraction"
                        _select="pointSelected"
                        empty-label="Sin datos para este periodo"
                        a11y-label="{{ $this->chartDescription }}"
                    />
                @endif

                @if ($selectedPoint !== null)
                    <native:column ref="explorer-selected-point" class="w-full rounded-md border border-theme-primary bg-theme-primary/10 p-3 gap-1">
                        <native:text class="text-xs font-semibold text-theme-on-surface-variant">Selección</native:text>
                        <native:text class="text-base font-bold text-theme-primary">{{ $selectedPoint }}</native:text>
                    </native:column>
                @endif
            @elseif ($loading)
                <native:column ref="explorer-loading-state" native:poll="2s" class="w-full rounded-lg bg-theme-surface p-5 gap-3">
                    <native:row class="w-full items-center gap-3">
                        <native:activity-indicator />
                        <native:text class="text-base font-semibold text-theme-on-surface">Cargando pronóstico</native:text>
                    </native:row>
                    <native:text class="text-sm text-theme-on-surface-variant">Preparando la gráfica para {{ $this->locationChoice }}.</native:text>
                </native:column>
            @elseif ($error === null)
                <native:column ref="explorer-no-data" class="w-full rounded-lg bg-theme-surface p-5 gap-3">
                    <native:icon name="chart.xyaxis.line" :size="28" class="text-theme-on-surface-variant" />
                    <native:text class="text-base font-semibold text-theme-on-surface">Sin datos para esta selección</native:text>
                    <native:text class="text-sm text-theme-on-surface-variant">Prueba otro periodo o actualiza el pronóstico.</native:text>
                    <native:button class="w-full" variant="secondary" @tap="refreshSeries">Actualizar datos</native:button>
                </native:column>
            @endif
        @endif
    </native:column>
</native:scroll-view>
