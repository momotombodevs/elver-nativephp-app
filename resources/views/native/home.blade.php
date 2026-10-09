<native:list ref="climate-summary-screen" class="w-full h-full bg-theme-background" plain>
    <native:column class="w-full p-4 gap-5">
        @if ($locationId === null)
            <native:column ref="summary-empty-state" class="w-full bg-theme-surface p-5 gap-3">
                <native:icon name="location.slash" :size="32" class="text-theme-primary" />
                <native:text class="text-xl font-bold text-theme-on-surface">{{ __('ui.home.no_location') }}</native:text>
                <native:text class="text-base font-semibold text-theme-on-surface">{{ __('ui.home.add_location_prompt') }}</native:text>
                <native:text class="text-sm text-theme-on-surface-variant">{{ __('ui.home.consult_zone') }}</native:text>
                <native:button ref="summary-add-location" class="w-full" size="lg" @navigate="'/locations'" :a11y-label="__('ui.common.add_location')">{{ __('ui.common.add_location') }}</native:button>
            </native:column>
        @else
            <native:column ref="summary-location-selector" class="w-full bg-theme-surface">
                <native:list-item
                    ref="summary-location"
                    headline="{{ __('ui.explorer.location') }}"
                    supporting="{{ $locationChoice }}"
                    leadingIcon="location_on"
                    :trailing-menu="$this->locationMenu()"
                    trailing-a11y-label="{{ __('ui.explorer.location') }}"
                    :a11y-label="__('ui.home.change_location')"
                />
            </native:column>

            @if ($error !== null)
                <native:column ref="summary-error" class="w-full bg-theme-surface-variant p-3 gap-2">
                    <native:text class="text-sm text-theme-destructive">{{ $error }}</native:text>
                    <native:button class="w-full" variant="ghost" @tap="refreshForecast">{{ __('ui.common.retry') }}</native:button>
                </native:column>
            @endif

            @if ($stale)
                <native:text ref="summary-stale-notice" class="w-full bg-theme-accent/10 p-3 text-sm font-bold text-theme-accent-foreground">{{ __('ui.home.offline') }}</native:text>
            @endif

            @if ($forecast !== [])
                @if ($loading)
                    <native:row ref="summary-loading" native:poll="2s" class="w-full items-center justify-center py-4">
                        <native:activity-indicator ref="summary-loading-indicator" a11y-label="{{ __('ui.common.loading') }}" />
                    </native:row>
                @endif

                <native:column ref="summary-current-conditions" class="w-full bg-theme-surface p-5 gap-4">
                    <native:row class="w-full items-center gap-3">
                        <native:column class="flex-1 gap-1">
                            <native:text class="text-lg font-bold text-theme-on-surface">{{ __('ui.home.current_conditions') }}</native:text>
                            <native:text class="text-sm text-theme-on-surface-variant">{{ __('ui.home.now_in', ['location' => $this->locationName]) }}</native:text>
                        </native:column>
                        <native:icon name="cloud" :size="28" class="text-theme-primary" />
                    </native:row>
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

                @if ($this->recommendations !== [])
                    <native:column ref="summary-recommendations" class="w-full bg-theme-secondary p-4 gap-3">
                        <native:text class="text-sm font-semibold text-theme-primary">{{ __('ui.home.recommendation_today') }}</native:text>
                        @foreach ($this->recommendations as $recommendation)
                            <native:column ref="recommendation-{{ $recommendation['code'] }}" class="gap-1">
                                <native:text class="text-base font-bold text-theme-on-surface">{{ $recommendation['title'] }}</native:text>
                                <native:text class="text-sm text-theme-on-surface-variant">{{ $recommendation['message'] }}</native:text>
                            </native:column>
                        @endforeach
                    </native:column>
                @endif

                <native:column ref="summary-rain" class="w-full bg-theme-surface-variant p-4 gap-2">
                    <native:row class="w-full items-center gap-3">
                        <native:icon name="water_drop" :size="24" class="text-theme-primary" />
                        <native:text class="text-base font-bold text-theme-on-surface">{{ __('ui.home.rain_next_24') }}</native:text>
                    </native:row>
                    @if ($this->next24HoursRain['noData'])
                        <native:text class="text-sm text-theme-on-surface-variant">{{ __('ui.home.no_rain_data') }}</native:text>
                    @else
                        <native:text class="text-2xl font-bold text-theme-accent-foreground">
                            {{ number_format($this->next24HoursRain['total'], 1, ',', '.') }} {{ $this->rainUnit }}
                        </native:text>
                        @if ($this->next24HoursRain['partial'])
                            <native:text ref="summary-rain-partial" class="text-sm text-theme-on-surface-variant">
                                {{ __('ui.home.partial_rain', ['reported' => $this->next24HoursRain['reportedHours'], 'expected' => $this->next24HoursRain['expectedHours']]) }}
                            </native:text>
                        @elseif (! $this->next24HoursRain['hasRain'])
                            <native:text ref="summary-rain-none" class="text-sm text-theme-on-surface-variant">{{ __('ui.home.no_expected_rain') }}</native:text>
                        @endif
                        @if ($this->next24HoursRain['periods'] !== [])
                            <native:text ref="summary-rain-periods" class="text-sm text-theme-on-surface-variant">
                                {{ __('ui.home.rain_hours', ['periods' => implode(' · ', $this->next24HoursRain['periods'])]) }}
                            </native:text>
                        @endif
                    @endif
                </native:column>

                <native:column ref="summary-chart-card" class="w-full bg-theme-surface p-4 gap-3">
                    <native:row class="w-full items-center justify-between">
                        <native:column class="gap-1">
                            <native:text class="text-lg font-bold text-theme-on-surface">{{ __('ui.home.next_24_hours') }}</native:text>
                            <native:text class="text-sm text-theme-on-surface-variant">{{ __('ui.home.temperature_chart', ['axis' => $this->temperatureYAxis['title']]) }}</native:text>
                        </native:column>
                        <native:button variant="ghost" @navigate="'/explorer'">{{ __('ui.home.explore') }}</native:button>
                    </native:row>
                    <native:line-chart
                        ref="summary-temperature-chart"
                        class="w-full h-64"
                        :series="$this->temperatureSeries"
                        :locale="$this->chartLocale"
                        :x-axis="['type' => 'datetime', 'dateFormat' => 'time', 'timezone' => $forecast['timezone'] ?? 'UTC']"
                        :y-axis="$this->temperatureYAxis"
                        :legend="['visible' => false]"
                        :style="['line' => ['width' => 3, 'interpolation' => 'smooth'], 'points' => ['visible' => false], 'axis' => ['labelCount' => 5]]"
                        :empty-label="__('ui.home.chart_empty_24')"
                        :a11y-label="__('ui.home.temperature_forecast_a11y', ['location' => $this->locationName])"
                    />
                </native:column>

                @if ($this->dailyForecast !== [])
                    <native:column class="w-full gap-2">
                        <native:text class="text-lg font-bold text-theme-on-surface">{{ __('ui.home.next_days') }}</native:text>
                        <native:scroll-view horizontal class="w-full">
                            <native:row class="gap-3">
                                @foreach ($this->dailyForecast as $day)
                                    <native:column class="w-32 bg-theme-surface-variant p-3 gap-1">
                                        <native:text class="text-sm font-semibold text-theme-on-surface">{{ $day['date'] }}</native:text>
                                        <native:text class="text-sm text-theme-on-surface-variant">{{ __('ui.home.maximum', ['value' => $day['maximum']]) }}</native:text>
                                        <native:text class="text-sm text-theme-accent-foreground">{{ $day['uv'] }}</native:text>
                                    </native:column>
                                @endforeach
                            </native:row>
                        </native:scroll-view>
                    </native:column>
                @endif

                @if ($this->currentWeatherDetails !== [])
                    <native:column ref="summary-weather-details" class="w-full bg-theme-surface-variant p-4 gap-3">
                        <native:row class="w-full items-center justify-between">
                            <native:text class="text-base font-bold text-theme-on-surface">{{ __('ui.home.more_data') }}</native:text>
                            <native:button size="sm" variant="ghost" @tap="openMetricHelp">{{ __('ui.home.what_means') }}</native:button>
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
                <native:column ref="summary-loading-state" native:poll="2s" class="w-full items-center justify-center bg-theme-surface py-8">
                    <native:activity-indicator ref="summary-loading-state-indicator" a11y-label="{{ __('ui.common.loading') }}" />
                </native:column>
            @endif
        @endif
    </native:column>
</native:list>

@if ($showMetricHelp)
    <native:list ref="metric-help" class="w-full bg-theme-background" plain separator>
        <native:list-section header="{{ __('ui.home.how_to_read') }}">
            <native:list-item headline="{{ __('ui.home.rain_mm') }}" supporting="{{ __('ui.home.rain_explanation') }}" leadingIcon="water_drop" />
            <native:list-item headline="{{ __('ui.home.uv_index') }}" supporting="{{ __('ui.home.uv_explanation') }}" leadingIcon="wb_sunny" />
            <native:list-item headline="{{ __('ui.home.humidity_feels_like') }}" supporting="{{ __('ui.home.humidity_explanation') }}" leadingIcon="humidity_high" />
            <native:button @tap="dismissMetricHelp">{{ __('ui.home.understood') }}</native:button>
        </native:list-section>
    </native:list>
@endif
