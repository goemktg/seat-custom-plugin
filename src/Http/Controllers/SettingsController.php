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
use Seat\Web\Models\Acl\Role;
use Seat\Web\Models\Squads\Squad;

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
            'role-id-squad-id-map' => 'required|json',
            'inactive-role-id' => 'required|exists:roles,id',
            'ignore-role-id' => 'required|exists:roles,id',
        ]);

        // JSON 구조 및 ID 존재 여부 검증
        $squadRoleMap = json_decode($request->input('role-id-squad-id-map'), true);
        
        if (!is_array($squadRoleMap)) {
            return redirect()->back()
                ->withErrors(['role-id-squad-id-map' => 'Invalid JSON format. Expected object with squad_id:role_id pairs.'])
                ->withInput();
        }

        // 각 squad_id와 role_id가 실제 존재하는지 검증
        $squadIds = array_keys($squadRoleMap);
        $roleIds = array_values($squadRoleMap);
        
        // Squad IDs 검증
        $existingSquadIds = Squad::whereIn('id', $squadIds)->pluck('id')->toArray();
        $invalidSquadIds = array_diff($squadIds, $existingSquadIds);
        
        if (!empty($invalidSquadIds)) {
            return redirect()->back()
                ->withErrors(['role-id-squad-id-map' => 'Invalid squad_id(s): ' . implode(', ', $invalidSquadIds)])
                ->withInput();
        }
        
        // Role IDs 검증
        $existingRoleIds = Role::whereIn('id', $roleIds)->pluck('id')->toArray();
        $invalidRoleIds = array_diff($roleIds, $existingRoleIds);
        
        if (!empty($invalidRoleIds)) {
            return redirect()->back()
                ->withErrors(['role-id-squad-id-map' => 'Invalid role_id(s): ' . implode(', ', $invalidRoleIds)])
                ->withInput();
        }

        // Inactive Role이 Squad Map에 포함되어 있는지 검증
        $inactiveRoleId = $request->input('inactive-role-id');
        if (in_array($inactiveRoleId, $roleIds)) {
            return redirect()->back()
                ->withErrors(['role-id-squad-id-map' => 'The inactive role (ID: ' . $inactiveRoleId . ') cannot be included in the squad mapping as it will be ignored.'])
                ->withInput();
        }

        // Ignore Role이 Squad Map에 포함되어 있는지 검증
        $ignoreRoleId = $request->input('ignore-role-id');
        if (in_array($ignoreRoleId, $roleIds)) {
            return redirect()->back()
                ->withErrors(['role-id-squad-id-map' => 'The ignore role (ID: ' . $ignoreRoleId . ') cannot be included in the squad mapping.'])
                ->withInput();
        }

        setting(['custom-plugin.role_id_squad_id_map', $request->input('role-id-squad-id-map')], true);
        setting(['custom-plugin.inactive_role_id', $request->input('inactive-role-id')], true);
        setting(['custom-plugin.ignore_role_id', $request->input('ignore-role-id')], true);

        return redirect()->back()
            ->with('success', 'Custom Plugin settings have been updated.');
    }
}
