<native:top-bar title="Alertas" />

<native:column ref="climate-alerts-screen" class="w-full h-full bg-theme-background">
    <native:column class="w-full p-4 gap-4">
        @if ($this->locationOptions === [])
            <native:column ref="alerts-empty-location" class="w-full rounded-lg bg-theme-surface p-5 gap-3">
                <native:icon name="bell.slash" :size="32" class="text-theme-primary" />
                <native:text class="text-xl font-bold text-theme-on-surface">Agrega una ubicación</native:text>
                <native:text class="text-sm text-theme-on-surface-variant">Después podrás crear alertas.</native:text>
                <native:button class="w-full" size="lg" @navigate="'/locations'">Agregar ubicación</native:button>
            </native:column>
        @else
            @if ($error !== null)
                <native:text ref="alerts-error" class="text-sm font-semibold text-theme-destructive">{{ $error }}</native:text>
            @endif

            <native:column ref="alerts-notification-access" class="w-full rounded-lg bg-theme-surface p-4 gap-2">
                <native:text class="text-base font-bold text-theme-on-surface">Avisos con la app cerrada</native:text>
                <native:text class="text-sm text-theme-on-surface-variant">El sistema decide cuándo revisar según la conexión, la batería y los permisos. Las revisiones pueden retrasarse o pausarse.</native:text>
                @if ($notificationAccess === true)
                    <native:text ref="alerts-notification-permission-granted" class="text-sm font-semibold text-theme-primary">Permiso de notificaciones activo</native:text>
                @elseif ($notificationAccess === false)
                    <native:text ref="alerts-notification-permission-denied" class="text-sm text-theme-on-surface-variant">
                        {{ $notificationAccessMessage ?? 'Permite las notificaciones para recibir avisos cuando AgroClima esté cerrada.' }}
                    </native:text>
                    <native:row class="w-full gap-2">
                        <native:button class="flex-1" variant="secondary" @tap="requestNotificationAccess" a11y-label="Activar notificaciones climáticas">Activar notificaciones</native:button>
                        <native:button class="flex-1" variant="secondary" @tap="openNotificationSettings" a11y-label="Abrir ajustes de AgroClima">Abrir ajustes</native:button>
                    </native:row>
                @else
                    <native:row ref="alerts-notification-permission-checking" class="w-full items-center gap-2">
                        <native:activity-indicator />
                        <native:text class="text-sm text-theme-on-surface-variant">Revisando permisos…</native:text>
                    </native:row>
                @endif
            </native:column>

            @if ($forecastRefreshLoading)
                <native:row ref="alerts-forecast-refresh" native:poll="2s" class="w-full items-center gap-2">
                    <native:activity-indicator />
                    <native:text class="text-sm text-theme-on-surface-variant">Revisando las condiciones para tus alertas…</native:text>
                </native:row>
            @elseif ($forecastRefreshError !== null)
                <native:column ref="alerts-forecast-error" class="w-full rounded-md border border-theme-destructive p-3 gap-2">
                    <native:text class="text-sm text-theme-destructive">{{ $forecastRefreshError }}</native:text>
                    <native:button class="w-full" variant="secondary" @tap="refreshAlerts">Reintentar</native:button>
                </native:column>
            @endif

            <native:text class="text-xl font-bold text-theme-on-surface">Tus alertas</native:text>

            @if ($alertRows === [])
                <native:column ref="alerts-empty-state" class="w-full items-center rounded-lg border border-theme-outline p-5 gap-2">
                    <native:icon name="bell" :size="32" class="text-theme-on-surface-variant" />
                    <native:text class="text-base font-bold text-theme-on-surface">Sin alertas</native:text>
                    <native:text class="text-sm text-center text-theme-on-surface-variant">Recibe avisos cuando cambien las condiciones.</native:text>
                    <native:button ref="create-alert-action" class="w-full" variant="secondary" @tap="openCreateAlert">Crear alerta</native:button>
                </native:column>
            @endif
        @endif
    </native:column>

    @if ($alertRows !== [])
        <native:list class="w-full flex-1" plain separator>
            @foreach ($alertRows as $alert)
                <native:list-item
                    key="alert-{{ $alert['id'] }}"
                    ref="toggle-alert-{{ $alert['id'] }}"
                    headline="{{ $alert['metric'] }}"
                    supporting="{{ $alert['operator'] }} {{ $alert['threshold'] }} · {{ $alert['state'] }}"
                    overline="{{ $alert['enabled'] ? 'Activa' : 'Pausada' }}"
                    leadingIcon="bell"
                    :trailingCheckbox="$alert['enabled']"
                    on-trailing-change="setAlertEnabled('{{ $alert['id'] }}')"
                    :trailing-actions="$alert['deleteActions']"
                    supportingColor="{{ $alert['state'] === 'Umbral superado' ? theme('destructive') : theme('on-surface-variant') }}"
                    overlineColor="{{ $alert['enabled'] ? theme('primary') : theme('on-surface-variant') }}"
                    @tap="toggleAlert('{{ $alert['id'] }}')"
                    @longPress="requestDeleteAlert('{{ $alert['id'] }}')"
                    a11y-label="Alerta de {{ $alert['metric'] }}, {{ $alert['enabled'] ? 'activa' : 'pausada' }}, {{ $alert['operator'] }} {{ $alert['threshold'] }}, {{ $alert['state'] }}"
                    a11y-hint="Toca para {{ $alert['enabled'] ? 'pausar' : 'activar' }}. Mantén presionado o desliza para eliminar."
                />
            @endforeach
        </native:list>
    @else
        <native:spacer />
    @endif
</native:column>

@if ($this->locationOptions !== [])
    <native:bottom-sheet
        ref="create-alert-sheet"
        :visible="$showCreateSheet"
        detents="medium,large"
        @dismiss="dismissCreateAlert"
        a11y-label="Nueva alerta climática"
    >
        <native:column class="w-full p-5 gap-4 bg-theme-surface">
            <native:text class="text-xl font-bold text-theme-on-surface">Nueva alerta</native:text>
            <native:select ref="alert-location" label="Ubicación" :options="$this->locationOptions" native:model="locationChoice" a11y-label="Ubicación de la alerta" />
            <native:row class="w-full gap-3">
                <native:select ref="alert-metric" class="flex-1" label="Variable" :options="['Temperatura', 'Humedad', 'Precipitación', 'Viento']" native:model="metricChoice" a11y-label="Variable climática de la alerta" />
                <native:select ref="alert-operator" class="flex-1" label="Condición" :options="['Mayor que', 'Menor que']" native:model="operatorChoice" a11y-label="Condición del umbral" />
            </native:row>
            <native:outlined-text-input
                ref="alert-threshold"
                label="Umbral ({{ $this->thresholdUnit }})"
                placeholder="Ej. 35"
                keyboard="decimal"
                native:model.blur="threshold"
                a11y-label="Valor del umbral en {{ $this->thresholdUnit }}"
            />
            @if ($error !== null)
                <native:text ref="create-alert-error" class="text-sm font-semibold text-theme-destructive">{{ $error }}</native:text>
            @endif
            <native:button ref="create-alert" class="w-full" size="lg" @tap="createAlert">Guardar alerta</native:button>
        </native:column>
    </native:bottom-sheet>

    <native:fab ref="add-alert-fab" icon="add" @tap="openCreateAlert" a11y-label="Crear alerta" />
@endif
