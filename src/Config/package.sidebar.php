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
    'custom-plugin' => [
        'name'          => 'Custom Plugin',
        'label'         => 'custom-plugin::menu.main_level',
        'icon'          => 'fas fa-tools',
        'route_segment' => 'custom-plugin',
        'entries'       => [
            // no 'permission' key: corporation-scoped abilities need a
            // corporation, so each page lists only the corporations allowed
            [
                'name'          => 'Hangar Check',
                'label'         => 'custom-plugin::menu.hangar_check',
                'icon'          => 'fas fa-clipboard-check',
                'route'         => 'custom-plugin.hangar.index',
            ],
            [
                'name'          => 'Doctrine Fits',
                'label'         => 'custom-plugin::menu.doctrine_fits',
                'icon'          => 'fas fa-rocket',
                'route'         => 'custom-plugin.fits.pick',
            ],
            [
                'name'          => 'Settings',
                'label'         => 'custom-plugin::menu.settings',
                'icon'          => 'fas fa-cogs',
                'permission'    => 'global.superuser',
                'route'         => 'custom-plugin.settings',
            ],
        ],
    ],
];
