<native:top-bar title="Ubicaciones" />

<native:column ref="saved-locations-screen" class="w-full h-full bg-theme-background">
    @if ($locations->isEmpty())
        <native:column ref="locations-empty-state" class="flex-1 w-full items-center justify-center px-8 pb-24 gap-3">
            <native:icon name="location_on" :size="40" class="text-theme-primary" />
            <native:text class="text-lg font-bold text-center text-theme-on-surface">Agrega una ubicación</native:text>
            <native:text class="text-sm text-center text-theme-on-surface-variant">Guarda tu primera ubicación para consultar el clima local.</native:text>
            <native:button
                ref="locations-add-first"
                class="w-full"
                size="lg"
                @tap="openAddLocation"
                a11y-label="Agregar primera ubicación"
            >
                Agregar ubicación
            </native:button>
        </native:column>
    @else
        <native:list ref="locations-list" class="w-full flex-1">
            <native:list-section
                header="Mis ubicaciones"
                footer="Toca para elegir · mantén presionado o desliza para eliminar."
            >
                @foreach ($locations as $location)
                    @if ($location->is_default)
                        <native:list-item
                            key="location-{{ $location->id }}-{{ $locationListVersion }}"
                            ref="location-{{ $location->id }}"
                            headline="{{ $location->name }}"
                            supporting="{{ number_format((float) $location->latitude, 4) }}, {{ number_format((float) $location->longitude, 4) }}"
                            overline="Principal"
                            leadingIcon="location_on"
                            trailingIcon="check"
                            :trailing-actions="$deleteActions[$location->id]"
                            @longPress="deleteLocation('{{ $location->id }}')"
                            a11y-label="{{ $location->name }}, ubicación principal"
                            a11y-hint="Mantén presionado para eliminar"
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
                            a11y-hint="Toca para elegir. Mantén presionado para eliminar"
                        />
                    @endif
                @endforeach
            </native:list-section>
        </native:list>
    @endif
</native:column>

<native:bottom-sheet
    :visible="$showAddLocation"
    detents="0.4,large"
    @dismiss="closeAddLocation"
    a11y-label="Agregar ubicación"
    a11y-hint="Guarda tu ubicación actual"
>
    <native:column class="w-full bg-theme-surface p-5 gap-4">
        <native:column class="w-full gap-1">
            <native:text class="text-xl font-bold text-theme-on-surface">Agregar ubicación</native:text>
            <native:text class="text-sm text-theme-on-surface-variant">Usaremos tu posición actual.</native:text>
        </native:column>

        <native:outlined-text-input
            ref="location-name"
            label="Nombre"
            placeholder="Ej. Finca norte"
            supporting="Opcional"
            native:model.blur="name"
            a11y-label="Nombre de la ubicación"
        />

        @if ($error !== null)
            <native:text ref="locations-error" class="text-sm font-semibold text-theme-destructive">{{ $error }}</native:text>
        @endif

        <native:column class="w-full gap-3">
            @if ($locationPermission === 'permanently_denied')
                <native:button
                    ref="open-location-settings"
                    class="w-full"
                    size="lg"
                    variant="secondary"
                    @tap="openAppSettings"
                    a11y-label="Abrir ajustes de Elver"
                >
                    Abrir Ajustes
                </native:button>
            @else
                <native:button
                    ref="use-current-location"
                    class="w-full"
                    size="lg"
                    :disabled="$locating"
                    :loading="$locating"
                    @tap="useCurrentLocation"
                    a11y-label="Usar ubicación actual"
                    a11y-hint="Solicita permiso y guarda las coordenadas de este dispositivo"
                >
                    {{ $locating ? 'Buscando ubicación…' : 'Usar ubicación actual' }}
                </native:button>
            @endif
        </native:column>
    </native:column>
</native:bottom-sheet>

@if ($locations->isNotEmpty())
    <native:fab
        ref="add-location-fab"
        icon="add"
        @tap="openAddLocation"
        a11y-label="Agregar ubicación"
        a11y-hint="Abre la captura de ubicación"
    />
@endif
