<?php

/**
 * Native UI — Theme Tokens
 *
 * Published via `php artisan vendor:publish --tag=native-ui-config`.
 * Edit to customize your app's visual identity in one place.
 *
 * For dynamic per-tenant theming, use Nativephp\NativeUi\Theme::merge([...])
 * from a service provider. Runtime merges deep-merge on top of these values.
 *
 * Decision log: /docs/NATIVE-UI-REWRITE-PLAN.md (D — theme layer)
 */

return [

    /*
    |---------------------------------------------------------------------------
    | Theme
    |---------------------------------------------------------------------------
    |
    | 19 color tokens, 4 radii, 4 font sizes, font family.
    |
    | "on-X" means "color of content placed ON a surface of color X"
    |   — i.e., text/icons on that background.
    |
    | Color tokens accept:
    |   - CSS hex: '#B91C1C', '#F00', or with alpha '#8B5CF680' (#RRGGBBAA)
    |   - Tailwind palette names: 'red-300', 'orange-800'
    |   - Opacity modifiers on either: 'red-300/20', '#8B5CF6/50'
    |
    | Dark mode is auto-derived from `light` when `dark` is not set. To opt
    | into explicit dark tokens, fill out the `dark` block.
    |
    | The default pairs meet WCAG AA (4.5:1) — if you customize, keep each
    | `on-*` color at 4.5:1 contrast against its background token.
    |
    */

    'theme' => [

        'light' => [
            // Primary brand color — used for filled buttons, active states, key accents.
            'primary' => '#5B3A91',
            'on-primary' => '#FFFFFF',

            // Secondary / muted action color.
            'secondary' => '#ECE7F2',
            'on-secondary' => '#382D43',

            // Surface = cards, sheets, dialogs. Background = page root.
            'surface' => '#FFFEFC',
            'on-surface' => '#211A27',
            'background' => '#F5F3F7',
            'on-background' => '#211A27',

            // Surface variant = filled text fields, muted tonal surfaces.
            // on-surface-variant = muted label/hint text on those surfaces.
            'surface-variant' => '#EEEAF1',
            'on-surface-variant' => '#706677',

            // Outline = neutral borders (text fields, dividers, cards).
            'outline' => '#D2CAD8',
            'outline-variant' => '#E4DFE8',

            // Destructive actions — maps to `variant="destructive"` on components.
            'destructive' => '#B91C1C',
            'on-destructive' => '#FFFFFF',

            // Tertiary accent — for highlights, badges, emphasis not covered by primary.
            'accent' => '#E8DFF0',
            'on-accent' => '#382D43',
            // Accessible accent text on light surfaces.
            'accent-foreground' => '#5B3A91',
        ],

        'dark' => [
            // Leave empty or partial to auto-derive from `light` (luminance inversion).
            // Specify any token here to override the derived value.
            'primary' => '#B99DDD',
            'on-primary' => '#FFFFFF',

            'secondary' => '#352C3E',
            'on-secondary' => '#F5EFFA',

            'surface' => '#1A1520',
            'on-surface' => '#FBF9FF',
            'background' => '#110D15',
            'on-background' => '#FBF9FF',

            'surface-variant' => '#2C2632',
            'on-surface-variant' => '#D8CBE2',

            'outline' => '#6F617A',
            'outline-variant' => '#463B4E',

            'destructive' => '#F87171',
            'on-destructive' => '#1A1026',

            'accent' => '#4B3B59',
            'on-accent' => '#F7EEFF',
            'accent-foreground' => '#DCC6F1',
        ],

        // Corner radii (points / dp).
        'radius-sm' => 6,
        'radius-md' => 12,
        'radius-lg' => 20,
        'radius-full' => 9999,

        // Font size scale (points / sp).
        'font-sm' => 14,
        'font-md' => 16,
        'font-lg' => 20,
        'font-xl' => 24,
    ],

    'fonts' => [
        'default' => 'System',
        'accent' => 'Archivo+Black-Regular',
        'lobster' => 'Lobster+Two-Regular',
    ],

];
