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

namespace Goemktg\Seat\SeatCustomPlugin\Http\Controllers;


use Illuminate\Http\Request;
use Seat\Web\Http\Controllers\Controller;

/**
 * Class SettingsController.
 *
 * @package Goemktg\Seat\SeatCustomPlugin\Http\Controllers
 */
class SettingsController extends Controller
{
    /**
     * @return \Illuminate\View\View
     */
    public function index()
    {
        return view('custom-plugin::settings');
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\RedirectResponse
     * @throws \Seat\Services\Exceptions\SettingException
     */
    public function update(Request $request)
    {
        $request->validate([
            'role-id-squad-id-map'                 => 'required|json',
            'inactive-role-id'               => 'required|string',
        ]);

        setting(['custom-plugin.role_id_squad_id_map', $request->input('role-id-squad-id-map')], true);
        setting(['custom-plugin.inactive_role_id', $request->input('inactive-role-id')], true);

        return redirect()->back()
            ->with('success', 'Custom Plugin settings have been updated.');
    }
}
