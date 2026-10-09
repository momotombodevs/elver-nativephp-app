<native:top-bar title="{{ $fullScreenCreate ? __('ui.alerts.new_alert') : __('ui.alerts.title') }}" back />

<native:column ref="climate-alerts-screen" class="w-full h-full bg-theme-background">
    @if ($fullScreenCreate)
        <native:list ref="create-alert-form" class="w-full h-full bg-theme-background" plain separator>
            <native:list-section
                header="{{ __('ui.alerts.new_alert') }}"
                footer="{{ __('ui.alerts.alert_data_a11y') }}"
            >
                <native:list-item
                    ref="alert-location"
                    headline="{{ __('ui.explorer.location') }}"
                    supporting="{{ $locationChoice }}"
                    leadingIcon="location_on"
                    :trailing-menu="$this->locationMenu()"
                    trailing-a11y-label="{{ __('ui.explorer.location') }}"
                    :a11y-label="__('ui.alerts.alert_data_a11y')"
                />
                <native:list-item
                    ref="quick-alert-rain"
                    headline="{{ __('ui.alerts.rain') }}"
                    supporting="{{ __('ui.alerts.quick_notice') }}"
                    leadingIcon="water_drop"
                    @tap="chooseQuickAlert('rain')"
                />
                <native:list-item
                    ref="quick-alert-wind"
                    headline="{{ __('ui.alerts.wind') }}"
                    supporting="{{ __('ui.alerts.quick_notice') }}"
                    leadingIcon="air"
                    @tap="chooseQuickAlert('wind')"
                />
                <native:list-item
                    ref="quick-alert-heat"
                    headline="{{ __('ui.alerts.heat') }}"
                    supporting="{{ __('ui.alerts.quick_notice') }}"
                    leadingIcon="thermostat"
                    @tap="chooseQuickAlert('heat')"
                />
                <native:list-item
                    ref="quick-alert-cold"
                    headline="{{ __('ui.alerts.cold') }}"
                    supporting="{{ __('ui.alerts.quick_notice') }}"
                    leadingIcon="ac_unit"
                    @tap="chooseQuickAlert('cold')"
                />
                <native:list-item
                    ref="alert-metric"
                    headline="{{ __('ui.alerts.what_to_measure') }}"
                    supporting="{{ $metricChoice }}"
                    leadingIcon="monitoring"
                    :trailing-menu="$this->metricMenu()"
                    trailing-a11y-label="{{ __('ui.alerts.what_to_measure') }}"
                    :a11y-label="__('ui.alerts.alert_data_a11y')"
                />
                <native:list-item
                    ref="alert-operator"
                    headline="{{ __('ui.alerts.notify_if') }}"
                    supporting="{{ $operatorChoice }}"
                    leadingIcon="tune"
                    :trailing-menu="$this->operatorMenu()"
                    trailing-a11y-label="{{ __('ui.alerts.notify_if') }}"
                    :a11y-label="__('ui.alerts.when_to_notify_a11y')"
                />
                <native:filled-text-input
                    ref="alert-threshold"
                    :placeholder="__('ui.alerts.example_35')"
                    label="{{ __('ui.alerts.value', ['unit' => $this->thresholdUnit]) }}"
                    keyboard="decimal"
                    native:model.blur="threshold"
                    :a11y-label="__('ui.alerts.threshold_a11y', ['unit' => $this->thresholdUnit])"
                />
            </native:list-section>

            @if ($error !== null)
                <native:text ref="create-alert-error" class="p-4 text-sm font-semibold text-theme-destructive">{{ $error }}</native:text>
            @endif

            <native:button ref="create-alert" class="mx-4" size="lg" @tap="createAlert">
                {{ __('ui.alerts.save_alert') }}
            </native:button>
        </native:list>
    @else
        <native:list ref="alerts-content" class="w-full h-full bg-theme-background" plain separator>
            @if ($this->locationOptions === [])
                <native:list-section header="{{ __('ui.alerts.title') }}">
                    <native:list-item
                        ref="alerts-empty-location"
                        headline="{{ __('ui.common.add_location') }}"
                        supporting="{{ __('ui.alerts.after_location') }}"
                        leadingIcon="location_off"
                        trailingIcon="forward"
                        @navigate="'/locations/add'"
                    />
                </native:list-section>
            @else
                <native:list-section header="{{ __('ui.explorer.location') }}">
                    <native:list-item
                        ref="alerts-location"
                        headline="{{ __('ui.explorer.location') }}"
                        supporting="{{ $locationChoice }}"
                        leadingIcon="location_on"
                        :trailing-menu="$this->locationMenu()"
                        trailing-a11y-label="{{ __('ui.explorer.location') }}"
                        :a11y-label="__('ui.alerts.alert_data_a11y')"
                    />
                </native:list-section>

                @if ($error !== null)
                    <native:text ref="alerts-error" class="p-4 text-sm font-semibold text-theme-destructive">{{ $error }}</native:text>
                @endif

                <native:list-section header="{{ __('ui.alerts.notifications_active') }}">
                    @if ($notificationAccessState === 'granted')
                        <native:list-item
                            ref="alerts-notification-permission-granted"
                            headline="{{ __('ui.alerts.notifications_active') }}"
                            leadingIcon="notifications_active"
                        />
                    @elseif ($notificationAccessState === 'denied')
                        <native:list-item
                            ref="alerts-notification-access"
                            headline="{{ __('ui.alerts.enable_notifications') }}"
                            supporting="{{ $notificationAccessMessage ?? __('ui.alerts.notifications_settings') }}"
                            leadingIcon="notifications_off"
                            trailingIcon="forward"
                            @tap="openNotificationSettings"
                        />
                        <native:button variant="secondary" @tap="requestNotificationAccess">
                            {{ __('ui.common.activate') }}
                        </native:button>
                        <native:list-item
                            ref="alerts-open-system"
                            headline="{{ __('ui.common.open_system') }}"
                            leadingIcon="settings"
                            @tap="openNotificationSettings"
                        />
                    @elseif ($notificationAccessState === 'error')
                        <native:list-item
                            ref="alerts-notification-error"
                            headline="{{ __('ui.alerts.retry_permissions') }}"
                            supporting="{{ $notificationAccessMessage ?? __('ui.alerts.notifications_check_error') }}"
                            leadingIcon="error_outline"
                            trailingIcon="refresh"
                            @tap="refreshNotificationAccess"
                        />
                    @else
                        <native:column ref="alerts-notification-permission-checking" class="w-full items-center justify-center py-4">
                            <native:activity-indicator ref="alerts-notification-permission-indicator" a11y-label="{{ __('ui.common.checking_permissions') }}" />
                        </native:column>
                    @endif
                </native:list-section>

                @if ($forecastRefreshLoading)
                    <native:column ref="alerts-forecast-refresh" class="w-full items-center justify-center py-4">
                        <native:activity-indicator ref="alerts-forecast-refresh-indicator" a11y-label="{{ __('ui.alerts.updating_weather') }}" />
                    </native:column>
                @elseif ($forecastRefreshError !== null)
                    <native:list-item
                        ref="alerts-forecast-error"
                        headline="{{ $forecastRefreshError }}"
                        leadingIcon="error_outline"
                        trailingIcon="refresh"
                        @tap="refreshAlerts"
                    />
                @endif

                @if ($automaticRecommendations !== [])
                    <native:list-section header="{{ __('ui.alerts.recommendations_title') }}">
                        @foreach ($automaticRecommendations as $recommendation)
                            <native:list-item
                                ref="automatic-recommendation-{{ $recommendation['code'] }}"
                                headline="{{ $recommendation['title'] }}"
                                supporting="{{ $recommendation['message'] }}"
                                leadingIcon="lightbulb"
                            />
                        @endforeach
                    </native:list-section>
                @endif

                <native:list-section header="{{ __('ui.alerts.your_alerts') }}">
                    <native:text ref="alerts-heading" class="px-4 pt-4 text-xl font-bold text-theme-on-surface">{{ __('ui.alerts.your_alerts') }}</native:text>
                    @if ($alertRows === [])
                        <native:list-item
                            ref="create-alert-action"
                            headline="{{ __('ui.alerts.no_alerts') }}"
                            supporting="{{ __('ui.alerts.create_for_value') }}"
                            leadingIcon="notifications_none"
                            trailingIcon="forward"
                            @tap="openCreateAlert"
                        />
                    @else
                        @foreach ($alertRows as $alert)
                            <native:list-item
                                key="alert-{{ $alert['id'] }}"
                                ref="toggle-alert-{{ $alert['id'] }}"
                                headline="{{ $alert['metric'] }}"
                                supporting="{{ $alert['location'] }} · {{ $alert['operator'] }} {{ $alert['threshold'] }} · {{ $alert['state'] }} · {{ $alert['lastTriggered'] }}"
                                overline="{{ $alert['enabled'] ? __('ui.alerts.active') : __('ui.alerts.paused') }} · {{ $alert['location'] }}"
                                leadingIcon="notifications"
                                :trailingCheckbox="$alert['enabled']"
                                on-trailing-change="setAlertEnabled('{{ $alert['id'] }}')"
                                :trailing-actions="$alert['deleteActions']"
                                :supportingColor="$alert['isExceeded'] ? theme('destructive') : theme('on-surface-variant')"
                                :overlineColor="$alert['enabled'] ? theme('primary') : theme('on-surface-variant')"
                                @tap="toggleAlert('{{ $alert['id'] }}')"
                                @longPress="requestDeleteAlert('{{ $alert['id'] }}')"
                                :a11y-label="__('ui.alerts.alert_a11y', ['metric' => $alert['metric'], 'location' => $alert['location'], 'status' => $alert['enabled'] ? __('ui.alerts.active') : __('ui.alerts.paused'), 'operator' => $alert['operator'], 'threshold' => $alert['threshold'], 'state' => $alert['state'], 'last_triggered' => $alert['lastTriggered']])"
                                :a11y-hint="__('ui.alerts.alert_hint', ['action' => $alert['enabled'] ? __('ui.alerts.pause') : __('ui.alerts.activate_lower')])"
                            />
                        @endforeach
                    @endif
                </native:list-section>
            @endif
        </native:list>

        @if ($this->locationOptions !== [])
            <native:fab ref="add-alert-fab" icon="add" @tap="openCreateAlert" :a11y-label="__('ui.alerts.create_alert')" />
        @endif
    @endif
</native:column>
