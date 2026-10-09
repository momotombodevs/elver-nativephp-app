@if ($fullScreenAddLocation)
    <native:top-bar title="{{ __('ui.locations.sheet_title') }}" back />

    <native:list ref="add-location-screen" class="w-full h-full bg-theme-background" plain separator>
        <native:list-section
            header="{{ __('ui.locations.sheet_title') }}"
        >
            <native:filled-text-input
                ref="location-name"
                label="{{ __('ui.locations.farm_a11y') }}"
                :placeholder="__('ui.locations.farm_example')"
                max-length="255"
                native:model.blur="name"
                :a11y-label="__('ui.locations.farm_a11y')"
            />
            <native:list-item
                ref="location-gps-info"
                headline="{{ __('ui.locations.current_location_title') }}"
                supporting="{{ __('ui.locations.current_location_description') }}"
                leadingIcon="my_location"
            />
        </native:list-section>

        @if ($error !== null)
            <native:text ref="locations-error" class="p-4 text-sm font-semibold text-theme-destructive">{{ $error }}</native:text>
        @endif

        @if ($locationPermission === 'permanently_denied')
            <native:button
                ref="open-location-settings"
                class="mx-4"
                size="lg"
                variant="secondary"
                @tap="openAppSettings"
                :a11y-label="__('ui.locations.open_app_settings')"
            >
                {{ __('ui.locations.open_settings') }}
            </native:button>
        @else
            <native:button
                ref="use-current-location"
                class="mx-4"
                size="lg"
                :disabled="$locating"
                :loading="$locating"
                @tap="useCurrentLocation"
                :a11y-label="__('ui.locations.use_current')"
                :a11y-hint="__('ui.locations.use_current_hint')"
            >
                {{ $locating ? __('ui.locations.searching') : __('ui.locations.use_current') }}
            </native:button>
        @endif
    </native:list>
@else
    <native:column ref="saved-locations-screen" class="w-full h-full bg-theme-background">
        @if ($locations->isEmpty())
            <native:column ref="locations-empty-state" class="flex-1 w-full items-center justify-center px-8 pb-24 gap-3">
                <native:icon name="location_on" :size="40" class="text-theme-primary" />
                <native:text class="text-lg font-bold text-center text-theme-on-surface">{{ __('ui.locations.empty_title') }}</native:text>
                <native:text class="text-sm text-center text-theme-on-surface-variant">{{ __('ui.locations.empty_description') }}</native:text>
                <native:button
                    ref="locations-add-first"
                    class="w-full"
                    size="lg"
                    @tap="openAddLocation"
                    :a11y-label="__('ui.locations.first_location_a11y')"
                >
                    {{ __('ui.common.add_location') }}
                </native:button>
            </native:column>
        @else
            <native:list ref="locations-list" class="w-full flex-1 bg-theme-background" plain separator>
                <native:list-section
                    header="{{ __('ui.locations.my_locations') }}"
                    footer="{{ __('ui.locations.list_footer') }}"
                >
                    @foreach ($locations as $location)
                        @if ($location->is_default)
                            <native:list-item
                                key="location-{{ $location->id }}-{{ $locationListVersion }}"
                                ref="location-{{ $location->id }}"
                                headline="{{ $location->name }}"
                                supporting="{{ number_format((float) $location->latitude, 4) }}, {{ number_format((float) $location->longitude, 4) }}"
                                overline="{{ __('ui.locations.primary') }}"
                                leadingIcon="location_on"
                                trailingIcon="check"
                                :trailing-actions="$deleteActions[$location->id]"
                                @longPress="deleteLocation('{{ $location->id }}')"
                                a11y-label="{{ $location->name }}, {{ __('ui.locations.primary') }}"
                                a11y-hint="{{ __('ui.locations.delete_hint') }}"
                            />
                        @else
                            <native:list-item
                                key="location-{{ $location->id }}-{{ $locationListVersion }}"
                                ref="location-{{ $location->id }}"
                                headline="{{ $location->name }}"
                                supporting="{{ number_format((float) $location->latitude, 4) }}, {{ number_format((float) $location->longitude, 4) }}"
                                leadingIcon="location_on"
                                trailingIcon="star_outline"
                                :trailing-actions="$deleteActions[$location->id]"
                                @press="makeDefault('{{ $location->id }}')"
                                @longPress="deleteLocation('{{ $location->id }}')"
                                a11y-label="{{ $location->name }}"
                                a11y-hint="{{ __('ui.locations.choose_delete_hint') }}"
                            />
                        @endif
                    @endforeach
                </native:list-section>
            </native:list>
        @endif
    </native:column>

    @if ($locations->isNotEmpty())
        <native:fab
            ref="add-location-fab"
            icon="add"
            @tap="openAddLocation"
            :a11y-label="__('ui.common.add_location')"
            :a11y-hint="__('ui.locations.capture_hint')"
        />
    @endif
@endif
