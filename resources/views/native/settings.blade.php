<native:top-bar title="Ajustes" />

<native:scroll-view ref="settings-screen" class="w-full h-full bg-theme-background">
    <native:column class="w-full p-4 gap-5">
        <native:column class="w-full rounded-lg bg-theme-surface p-4 gap-3">
            <native:text class="text-base font-bold text-theme-on-surface">Tu clima</native:text>
            @if ($this->locationOptions === [])
                <native:text class="text-sm text-theme-on-surface-variant">Agrega una ubicación para elegir la principal.</native:text>
                <native:button variant="secondary" @navigate="'/locations'">Agregar ubicación</native:button>
            @else
                <native:select ref="settings-default-location" label="Ubicación principal" :options="$this->locationOptions" native:model="defaultLocationChoice" />
            @endif
            <native:select ref="settings-units" label="Unidades" :options="['Métrico', 'Imperial']" native:model="unitsChoice" />
        </native:column>

        <native:column class="w-full rounded-lg bg-theme-surface p-4 gap-3">
            <native:text class="text-base font-bold text-theme-on-surface">Alertas</native:text>
            <native:toggle ref="settings-notifications" label="Permitir alertas locales" native:model="notificationsEnabled" />
            @if ($notificationPermissionGranted === true)
                <native:text class="text-sm text-theme-accent">Los avisos del sistema están activados.</native:text>
            @elseif ($notificationPermissionGranted === false)
                <native:text class="text-sm text-theme-on-surface-variant">Activa las notificaciones del sistema para recibir tus alertas configuradas.</native:text>
                <native:row class="w-full gap-2">
                    <native:button class="flex-1" variant="secondary" @tap="requestNotificationPermission">Activar</native:button>
                    <native:button class="flex-1" variant="secondary" @tap="openNotificationSettings">Ajustes</native:button>
                </native:row>
            @else
                <native:row class="items-center gap-2"><native:activity-indicator /><native:text class="text-sm text-theme-on-surface-variant">Revisando permisos…</native:text></native:row>
            @endif
        </native:column>

        <native:column class="w-full rounded-lg bg-theme-surface p-4 gap-2">
            <native:text class="text-base font-bold text-theme-on-surface">Datos</native:text>
            <native:text class="text-sm text-theme-on-surface-variant">Fuente meteorológica: Open-Meteo</native:text>
            <native:text class="text-sm text-theme-on-surface-variant">Elver {{ $this->appVersion }}</native:text>
        </native:column>
    </native:column>
</native:scroll-view>
