<native:top-bar title="{{ __('ui.settings.title') }}" back />

<native:list ref="settings-screen" class="w-full h-full bg-theme-background" plain separator>
    <native:list-section header="{{ __('ui.settings.appearance') }}" footer="{{ __('ui.settings.system_description') }}">
        <native:list-item
            ref="settings-appearance"
            headline="{{ __('ui.settings.theme') }}"
            supporting="{{ $appearanceMode }}"
            leadingIcon="palette"
            :trailing-menu="$this->appearanceMenu()"
            trailing-a11y-label="{{ __('ui.settings.theme') }}"
            :a11y-label="__('ui.settings.theme') . ': ' . $appearanceMode"
        />
    </native:list-section>

    <native:list-section header="{{ __('ui.settings.language') }}">
        <native:list-item
            ref="settings-locale"
            headline="{{ __('ui.settings.app_language') }}"
            supporting="{{ $localeChoice }}"
            leadingIcon="translate"
            :trailing-menu="$this->localeMenu()"
            trailing-a11y-label="{{ __('ui.settings.app_language') }}"
            :a11y-label="__('ui.settings.app_language') . ': ' . $localeChoice"
        />
    </native:list-section>

    <native:list-section header="{{ __('ui.settings.weather') }}">
        @if ($this->locationOptions === [])
            <native:list-item
                ref="settings-default-location"
                headline="{{ __('ui.settings.primary_location') }}"
                supporting="{{ __('ui.settings.choose_primary_location') }}"
                leadingIcon="location_on"
                trailingIcon="forward"
                @navigate="'/locations/add'"
            />
        @else
            <native:list-item
                ref="settings-default-location"
                headline="{{ __('ui.settings.primary_location') }}"
                supporting="{{ $defaultLocationChoice }}"
                leadingIcon="location_on"
                :trailing-menu="$this->locationMenu()"
                trailing-a11y-label="{{ __('ui.settings.primary_location') }}"
                :a11y-label="__('ui.settings.primary_location') . ': ' . $defaultLocationChoice"
            />
        @endif
        <native:list-item
            ref="settings-units"
            headline="{{ __('ui.settings.units') }}"
            supporting="{{ $unitsChoice }}"
            leadingIcon="device_thermostat"
            :trailing-menu="$this->unitsMenu()"
            trailing-a11y-label="{{ __('ui.settings.units') }}"
            :a11y-label="__('ui.settings.units') . ': ' . $unitsChoice"
        />
    </native:list-section>

    <native:list-section header="{{ __('ui.settings.alerts') }}">
        <native:list-item
            ref="settings-manage-alerts"
            headline="{{ __('ui.settings.manage_alerts') }}"
            supporting="{{ __('ui.settings.notification_note') }}"
            leadingIcon="notifications"
            trailingIcon="forward"
            @navigate="'/alerts'"
        />
        <native:row ref="settings-notifications" class="w-full items-center justify-between gap-4 px-4 py-3">
            <native:text class="flex-1 text-base text-theme-on-surface">{{ __('ui.settings.local_notifications') }}</native:text>
            <native:toggle native:model="notificationsEnabled" :a11y-label="__('ui.settings.local_notifications')" />
        </native:row>
        <native:row ref="settings-critical" class="w-full items-center justify-between gap-4 px-4 py-3">
            <native:text class="flex-1 text-base text-theme-on-surface">{{ __('ui.settings.important_alerts') }}</native:text>
            <native:toggle native:model="notificationCritical" :a11y-label="__('ui.settings.important_alerts')" />
        </native:row>
        <native:row ref="settings-recommendations" class="w-full items-center justify-between gap-4 px-4 py-3">
            <native:text class="flex-1 text-base text-theme-on-surface">{{ __('ui.settings.useful_recommendations') }}</native:text>
            <native:toggle native:model="notificationRecommendations" :a11y-label="__('ui.settings.useful_recommendations')" />
        </native:row>
        <native:row ref="settings-rapid-changes" class="w-full items-center justify-between gap-4 px-4 py-3">
            <native:text class="flex-1 text-base text-theme-on-surface">{{ __('ui.settings.rapid_changes') }}</native:text>
            <native:toggle native:model="notificationRapidChanges" :a11y-label="__('ui.settings.rapid_changes')" />
        </native:row>
        <native:row ref="settings-daily-summary" class="w-full items-center justify-between gap-4 px-4 py-3">
            <native:text class="flex-1 text-base text-theme-on-surface">{{ __('ui.settings.daily_summary') }}</native:text>
            <native:toggle native:model="notificationDailySummary" :a11y-label="__('ui.settings.daily_summary')" />
        </native:row>

        @if ($notificationPermissionGranted === true)
            <native:list-item
                ref="settings-notification-status"
                headline="{{ __('ui.settings.system_notifications_active') }}"
                leadingIcon="check_circle"
            />
        @elseif ($notificationPermissionGranted === false)
            <native:list-item
                ref="settings-notification-permission"
                headline="{{ __('ui.settings.enable_configured_notifications') }}"
                supporting="{{ __('ui.common.app_settings') }}"
                leadingIcon="notifications_off"
                trailingIcon="forward"
                @tap="openNotificationSettings"
            />
        @else
            <native:column
                ref="settings-notification-checking"
                class="w-full items-center justify-center py-4"
            >
                <native:activity-indicator
                    ref="settings-notification-checking-indicator"
                    a11y-label="{{ __('ui.common.checking_permissions') }}"
                />
            </native:column>
        @endif
    </native:list-section>
</native:list>
