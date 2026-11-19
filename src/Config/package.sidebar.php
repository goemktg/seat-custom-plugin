<?php

/*
This file is part of SeAT

Copyright (C) 2026 Goem Funaila

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License along
with this program; if not, write to the Free Software Foundation, Inc.,
51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
*/

return [

    // Integrating with the SeAT menu is defined here.
    // Refer to the web package for a structure reference.
    //
    // SeAT sorts the groups by key and opens a group when the first URL
    // segment equals its route_segment, so each group needs its own segment
    // (see Http/routes.php).
    'custom-plugin' => [
        'name'          => 'Custom Plugin',
        'label'         => 'custom-plugin::menu.main_level',
        'icon'          => 'fas fa-tools',
        'route_segment' => 'custom-plugin',
        // hide the whole group when no entry below is visible
        'permission'    => ['global.superuser'],
        'entries'       => [
            [
                'name'          => 'Settings',
                'label'         => 'custom-plugin::menu.settings',
                'icon'          => 'fas fa-cogs',
                'permission'    => 'global.superuser',
                'route'         => 'custom-plugin.settings',
            ],
        ],
    ],
    'custom-plugin-inventory' => [
        'name'          => 'Inventory',
        'label'         => 'custom-plugin::menu.inventory',
        'icon'          => 'fas fa-boxes',
        'route_segment' => 'inventory',
        // hide the whole group when no entry below is visible
        'permission'    => ['custom-plugin.doctrine_menu'],
        'entries'       => [
            // the menu ability is defined in SeatCustomPluginServiceProvider::add_menu_gate()
            [
                'name'          => 'Hangar Check',
                'label'         => 'custom-plugin::menu.hangar_check',
                'icon'          => 'fas fa-clipboard-check',
                'permission'    => 'custom-plugin.doctrine_menu',
                'route'         => 'custom-plugin.hangar.index',
            ],
            [
                'name'          => 'Doctrine Fits',
                'label'         => 'custom-plugin::menu.doctrine_fits',
                'icon'          => 'fas fa-rocket',
                'permission'    => 'custom-plugin.doctrine_menu',
                'route'         => 'custom-plugin.fits.pick',
            ],
        ],
    ],
];
