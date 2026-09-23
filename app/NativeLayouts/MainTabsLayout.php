<?php

namespace App\NativeLayouts;

use Native\Mobile\Edge\Layouts\Builders\Tab;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\Layouts\NativeLayout;
use Native\Mobile\Edge\NativeComponent;

class MainTabsLayout extends NativeLayout
{
    public function usesNativeChrome(): bool
    {
        return true;
    }

    public function tabBar(NativeComponent $screen): TabBar
    {
        return TabBar::make()
            ->add(Tab::link('Resumen', '/', ios: 'cloud.sun.fill', android: 'wb_cloudy'))
            ->add(Tab::link('Gráficas', '/explorer', ios: 'chart.xyaxis.line', android: 'monitoring'))
            ->add(Tab::link('Ubicaciones', '/locations', ios: 'map.fill', android: 'map'))
            ->add(Tab::link('Alertas', '/alerts', ios: 'bell.badge.fill', android: 'notifications_active'))
            ->activeColor(theme('primary'))
            ->labelVisibility('labeled');
    }
}
