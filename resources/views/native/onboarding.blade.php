<native:scroll-view class="w-full h-full bg-theme-background">
    <native:column class="w-full p-5 gap-5">
        <native:column class="w-full items-center gap-3 pt-2">
            <native:column class="w-28 h-28 rounded-lg bg-theme-primary p-3 items-center justify-center">
                <native:image src="{{ public_path('icon.png') }}" class="w-full h-full rounded-md" fit="2" alt="Icono de Elver" />
            </native:column>
            <native:text class="text-xs font-bold text-theme-primary">BIENVENIDO A ELVER</native:text>
            <native:text class="text-3xl font-bold text-center text-theme-on-surface">Clima claro para tus lugares</native:text>
            <native:text class="text-base text-center text-theme-on-surface-variant">Consulta el clima y recibe avisos.</native:text>
        </native:column>

        <native:column class="w-full rounded-lg bg-theme-surface p-4 gap-3">
            <native:text class="text-sm font-bold text-theme-on-surface">Todo lo esencial, de un vistazo</native:text>
            <native:row class="w-full items-start gap-3 p-2">
                <native:icon name="location_on" :size="24" class="text-theme-primary" />
                <native:text class="flex-1 text-sm text-theme-on-surface">Guarda un lugar para ver el clima.</native:text>
            </native:row>
            <native:row class="w-full items-start gap-3 p-2">
                <native:icon name="cloud_off" :size="24" class="text-theme-primary" />
                <native:text class="flex-1 text-sm text-theme-on-surface">Ve el último pronóstico sin internet.</native:text>
            </native:row>
            <native:row class="w-full items-start gap-3 p-2">
                <native:icon name="notifications_active" :size="24" class="text-theme-primary" />
                <native:text class="flex-1 text-sm text-theme-on-surface">Recibe avisos si cambia el clima.</native:text>
            </native:row>
        </native:column>

        <native:column class="w-full gap-3">
            <native:button ref="onboarding-add-location" class="w-full" size="lg" @tap="startAddingLocation" a11y-label="Agregar una ubicación" a11y-hint="Guarda tu primera ubicación para consultar el clima">Agregar ubicación</native:button>
            <native:button ref="onboarding-skip" class="w-full" variant="secondary" @tap="{{ $skipAction ?? 'skip' }}" a11y-label="Continuar sin agregar ubicación">Ahora no</native:button>
            <native:text class="text-xs text-center text-theme-on-surface-variant">Puedes hacerlo más tarde.</native:text>
        </native:column>
    </native:column>
</native:scroll-view>
