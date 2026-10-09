<?php

namespace App\NativeLayouts;

use App\Services\Localization\LocalePreferences;
use Native\Mobile\Edge\Layouts\Builders\NavAction;
use Native\Mobile\Edge\Layouts\Builders\NavBar;
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

    public function navBar(NativeComponent $screen): NavBar
    {
        return NavBar::make()
            ->title($screen->navTitle())
            ->displayMode('inline')
            ->scrollBehavior('pinned')
            ->backgroundColor(theme('surface'))
            ->textColor(theme('on-surface'))
            ->elevation(0)
            ->action(
                NavAction::make('open-settings')
                    ->label(__('ui.common.app_settings'))
                    ->a11yLabel(__('ui.common.app_settings'))
                    ->icon('settings')
                    ->url('/settings'),
            );
    }

    public function tabBar(NativeComponent $screen): TabBar
    {
        app(LocalePreferences::class)->apply();

        return TabBar::make()
            ->add(Tab::link(__('ui.navigation.summary'), '/', ios: 'cloud.sun.fill', android: 'wb_cloudy'))
            ->add(Tab::link(__('ui.navigation.charts'), '/explorer', ios: 'chart.xyaxis.line', android: 'monitoring'))
            ->add(Tab::link(__('ui.navigation.locations'), '/locations', ios: 'map.fill', android: 'map'))
            ->activeColor(theme('primary'))
            ->backgroundColor(theme('surface'))
            ->textColor(theme('on-surface-variant'))
            ->labelVisibility('labeled');
    }
}
