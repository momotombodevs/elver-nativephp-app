<native:list ref="chart-explorer-screen" class="w-full h-full bg-theme-background" plain>
    <native:column class="w-full p-4 gap-5">
        @if ($this->locationOptions === [])
            <native:column ref="explorer-empty-state" class="w-full bg-theme-surface p-5 gap-3">
                <native:icon name="chart.xyaxis.line" :size="32" class="text-theme-primary" />
                <native:text class="text-xl font-bold text-theme-on-surface">{{ __('ui.explorer.no_data') }}</native:text>
                <native:text class="text-sm text-theme-on-surface-variant">{{ __('ui.explorer.forecast_location') }}</native:text>
                <native:button class="w-full" size="lg" @navigate="'/locations'">{{ __('ui.common.add_location') }}</native:button>
            </native:column>
        @else
            <native:column ref="explorer-filters" class="w-full bg-theme-surface">
                <native:list-item
                    ref="explorer-location"
                    headline="{{ __('ui.explorer.location') }}"
                    supporting="{{ $locationChoice }}"
                    leadingIcon="location_on"
                    :trailing-menu="$this->locationMenu()"
                    trailing-a11y-label="{{ __('ui.explorer.location') }}"
                    :a11y-label="__('ui.explorer.series_location_a11y')"
                />
                <native:list-item
                    ref="explorer-metric"
                    headline="{{ __('ui.explorer.what_to_see') }}"
                    supporting="{{ $metricChoice }}"
                    leadingIcon="monitoring"
                    :trailing-menu="$this->metricMenu()"
                    trailing-a11y-label="{{ __('ui.explorer.what_to_see') }}"
                    :a11y-label="__('ui.explorer.weather_data_a11y')"
                />
                <native:list-item
                    ref="explorer-range"
                    headline="{{ __('ui.explorer.period') }}"
                    supporting="{{ $rangeChoice }}"
                    leadingIcon="schedule"
                    :trailing-menu="$this->rangeMenu()"
                    trailing-a11y-label="{{ __('ui.explorer.period') }}"
                    :a11y-label="__('ui.explorer.chart_period_a11y')"
                />
            </native:column>

            @if ($stale)
                <native:text ref="explorer-stale-notice" class="text-sm font-semibold text-theme-accent-foreground">{{ __('ui.explorer.offline') }}</native:text>
            @endif

            @if ($error !== null)
                <native:column ref="explorer-error" class="w-full bg-theme-surface-variant p-3 gap-2">
                    <native:text class="text-sm text-theme-destructive">{{ $error }}</native:text>
                    <native:button class="w-full" variant="ghost" @tap="refreshSeries">{{ __('ui.common.retry') }}</native:button>
                </native:column>
            @endif

            @if ($series !== [])
                <native:column ref="explorer-chart-card" class="w-full bg-theme-surface gap-4">
                    <native:row class="w-full items-end justify-between px-4 pt-4">
                        <native:column class="gap-1">
                            <native:text class="text-xl font-bold text-theme-on-surface">{{ $metricChoice }}</native:text>
                            <native:text class="text-sm text-theme-on-surface-variant">{{ $rangeChoice }}</native:text>
                        </native:column>
                    </native:row>
                    @if ($fetchedAtLabel !== null)
                        <native:text ref="explorer-updated" class="px-4 text-xs text-theme-on-surface-variant">{{ __('ui.home.updated', ['time' => $fetchedAtLabel]) }}</native:text>
                    @endif
                    @if ($loading)
                        <native:row ref="explorer-loading" class="w-full items-center justify-center py-4">
                            <native:activity-indicator ref="explorer-loading-indicator" a11y-label="{{ __('ui.common.loading') }}" />
                        </native:row>
                    @endif

                    @if ($this->chartKind === 'bar')
                        <native:bar-chart
                            ref="climate-bar-chart"
                            class="w-full h-72"
                            :series="$series"
                            :locale="$this->chartLocale"
                            :x-axis="$this->chartXAxis"
                            :y-axis="$this->chartYAxis"
                            :legend="['visible' => false]"
                            :style="$this->chartStyle"
                            :interaction="$this->chartInteraction"
                            _select="pointSelected"
                            :empty-label="__('ui.explorer.period_no_data')"
                            a11y-label="{{ $this->chartDescription }}"
                        />
                    @elseif ($this->chartKind === 'area')
                        <native:area-chart
                            ref="climate-area-chart"
                            class="w-full h-72"
                            :series="$series"
                            :locale="$this->chartLocale"
                            :x-axis="$this->chartXAxis"
                            :y-axis="$this->chartYAxis"
                            :legend="['visible' => false]"
                            :style="$this->chartStyle"
                            :interaction="$this->chartInteraction"
                            _select="pointSelected"
                            :empty-label="__('ui.explorer.period_no_data')"
                            a11y-label="{{ $this->chartDescription }}"
                        />
                    @else
                        <native:line-chart
                            ref="climate-line-chart"
                            class="w-full h-72"
                            :series="$series"
                            :locale="$this->chartLocale"
                            :x-axis="$this->chartXAxis"
                            :y-axis="$this->chartYAxis"
                            :legend="['visible' => false]"
                            :style="$this->chartStyle"
                            :interaction="$this->chartInteraction"
                            _select="pointSelected"
                            :empty-label="__('ui.explorer.period_no_data')"
                            a11y-label="{{ $this->chartDescription }}"
                        />
                    @endif

                    @if ($selectedPoint !== null)
                        <native:column ref="explorer-selected-point" class="mx-4 mb-4 bg-theme-primary/5 p-3 gap-1">
                            <native:text class="text-xs font-semibold text-theme-on-surface-variant">{{ __('ui.explorer.selection') }}</native:text>
                            <native:text class="text-base font-bold text-theme-primary">{{ $selectedPoint }}</native:text>
                        </native:column>
                    @endif
                </native:column>
            @elseif ($loading)
                <native:column ref="explorer-loading-state" native:poll="2s" class="w-full items-center justify-center bg-theme-surface py-8">
                    <native:activity-indicator ref="explorer-loading-state-indicator" a11y-label="{{ __('ui.common.loading') }}" />
                </native:column>
            @elseif ($error === null)
                    <native:column ref="explorer-no-data" class="w-full bg-theme-surface-variant p-5 gap-3">
                    <native:icon name="chart.xyaxis.line" :size="28" class="text-theme-on-surface-variant" />
                    <native:text class="text-base font-semibold text-theme-on-surface">{{ __('ui.explorer.selection_no_data') }}</native:text>
                    <native:text class="text-sm text-theme-on-surface-variant">{{ __('ui.explorer.try_period') }}</native:text>
                    <native:button class="w-full" variant="ghost" @tap="refreshSeries">{{ __('ui.explorer.update_data') }}</native:button>
                </native:column>
            @endif
        @endif
    </native:column>
</native:list>
