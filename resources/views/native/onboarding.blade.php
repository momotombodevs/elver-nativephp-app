<native:column class="w-full h-full items-center justify-center p-5 gap-4 bg-theme-background">
    <native:column class="w-full items-center gap-3 pt-2">
    <native:column class="w-36 h-36 rounded-2xl bg-theme-secondary p-3 items-center justify-center">
            <native:image src="{{ public_path('icon.png') }}" class="w-full h-full rounded-md" fit="2"
                alt="{{ __('ui.onboarding.icon_alt') }}" />
        </native:column>
        <native:text class="text-xs font-bold text-theme-primary">
            {{ __('ui.onboarding.welcome') }}
        </native:text>
        <native:text class="text-3xl font-bold text-center text-theme-on-surface">{{ __('ui.onboarding.title') }}
        </native:text>
        <native:text class="text-base text-center text-theme-on-surface-variant">{{ __('ui.onboarding.subtitle') }}
        </native:text>
    </native:column>

    <native:list ref="onboarding-features" class="w-full" plain separator>
        <native:list-section header="{{ __('ui.onboarding.essential') }}">
            <native:list-item headline="{{ __('ui.onboarding.feature_location') }}" leadingIcon="location_on" />
            <native:list-item headline="{{ __('ui.onboarding.feature_offline') }}" leadingIcon="cloud_off" />
            <native:list-item headline="{{ __('ui.onboarding.feature_alerts') }}" leadingIcon="notifications_active" />
        </native:list-section>
    </native:list>

    <native:column class="w-full gap-3">
        <native:button ref="onboarding-add-location" class="w-full" variant="primary" size="lg"
            @tap="startAddingLocation" a11y-label="{{ __('ui.common.add_location') }}"
            a11y-hint="{{ __('ui.onboarding.add_location_hint') }}">
            {{ __('ui.common.add_location') }}
        </native:button>
        <native:button ref="onboarding-skip" class="w-full" variant="ghost" @tap="{{ $skipAction ?? 'skip' }}"
            a11y-label="{{ __('ui.onboarding.skip_label') }}">
            {{ __('ui.onboarding.skip') }}
        </native:button>
    </native:column>
</native:column>
