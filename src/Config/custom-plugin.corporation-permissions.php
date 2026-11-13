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

// Corporation-scoped: a role can be limited to chosen corporations with a
// corporation filter. One permission covers both Hangar Check and Doctrine Fits.
// The key stays doctrine_hangar_view so roles granted on v0.1.5 keep working. CEOs and directors of the corporation always pass.
return [
    'doctrine_hangar_view' => [
        'label' => 'custom-plugin::permissions.doctrine_hangar_view_label',
        'description' => 'custom-plugin::permissions.doctrine_hangar_view_description',
        'division' => 'military',
    ],
];
