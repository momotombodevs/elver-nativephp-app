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
    | 17 color tokens, 4 radii, 4 font sizes, font family.
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
            'primary' => '#7F00FF',
            'on-primary' => '#FFFFFF',

            // Secondary / muted action color.
            'secondary' => '#EFE5FF',
            'on-secondary' => '#27004F',

            // Surface = cards, sheets, dialogs. Background = page root.
            'surface' => '#FFFFFF',
            'on-surface' => '#1A1026',
            'background' => '#F8F5FF',
            'on-background' => '#1A1026',

            // Surface variant = filled text fields, muted tonal surfaces.
            // on-surface-variant = muted label/hint text on those surfaces.
            'surface-variant' => '#F0E8FF',
            'on-surface-variant' => '#604D78',

            // Outline = neutral borders (text fields, dividers, cards).
            'outline' => '#D9C7F2',

            // Destructive actions — maps to `variant="destructive"` on components.
            'destructive' => '#B91C1C',
            'on-destructive' => '#FFFFFF',

            // Tertiary accent — for highlights, badges, emphasis not covered by primary.
            'accent' => '#006A70',
            'on-accent' => '#FFFFFF',
        ],

        'dark' => [
            // Leave empty or partial to auto-derive from `light` (luminance inversion).
            // Specify any token here to override the derived value.
            'primary' => '#7F00FF',
            'on-primary' => '#FFFFFF',

            'secondary' => '#32145A',
            'on-secondary' => '#F7EEFF',

            'surface' => '#170D25',
            'on-surface' => '#FBF9FF',
            'background' => '#0F0719',
            'on-background' => '#FBF9FF',

            'surface-variant' => '#2A173F',
            'on-surface-variant' => '#D9C8E9',

            'outline' => '#5C3D78',

            'destructive' => '#F87171',
            'on-destructive' => '#1A1026',

            'accent' => '#75DBD4',
            'on-accent' => '#0B3433',
        ],

        // Corner radii (points / dp).
        'radius-sm' => 4,
        'radius-md' => 8,
        'radius-lg' => 16,
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
