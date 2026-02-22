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

namespace Goemktg\Seat\SeatCustomPlugin\Commands;

use Illuminate\Console\Command;
use Seat\Web\Models\Acl\Role;
use Seat\Web\Models\Squads\Squad;
use Seat\Web\Models\User;

/**
 * Class SyncSquadsWithRoles.
 *
 * @package Goemktg\Seat\SeatCustomPlugin\Commands
 */
class SyncSquadsWithRoles extends Command
{
    /**
     * @var string
     */
    protected $signature = 'custom-plugin:sync-squads-with-roles
                            {--dry-run : Show what would be changed without actually making changes}';

    /**
     * @var string
     */
    protected $description = 'Synchronize user roles based on squad memberships according to configured mapping.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        // Get configuration
        $squadRoleMapJson = setting('custom-plugin.role_id_squad_id_map', true);
        $inactiveRoleId = setting('custom-plugin.inactive_role_id', true);
        $ignoreRoleIdsJson = setting('custom-plugin.ignore_role_ids', '[]');
        $ignoreRoleIds = json_decode($ignoreRoleIdsJson, true) ?: [];

        if (empty($squadRoleMapJson)) {
            $this->error('No squad-role mapping configured. Please configure it in Settings.');
            return 1;
        }

        $squadRoleMap = json_decode($squadRoleMapJson, true);
        
        if (!is_array($squadRoleMap)) {
            $this->error('Invalid squad-role mapping configuration.');
            return 1;
        }

        $this->info('Starting role synchronization based on squad memberships...');
        $this->info('Squad-Role Mapping: ' . $squadRoleMapJson);
        $this->info('Inactive Role ID: ' . ($inactiveRoleId ?: 'Not set'));
        $this->info('Ignore Role IDs: ' . (empty($ignoreRoleIds) ? 'None' : implode(', ', $ignoreRoleIds)));
        $this->newLine();

        $totalUsers = 0;
        $totalChanges = 0;

        // Process each user
        User::all()->each(function ($user) use ($squadRoleMap, $inactiveRoleId, $ignoreRoleIds, $isDryRun, &$totalUsers, &$totalChanges) {
            $totalUsers++;
            
            // Get user's squad memberships
            $userSquadIds = $user->squads()->pluck('squads.id')->toArray();
            
            // Determine which roles user should have based on squad memberships
            $targetRoleIds = [];
            foreach ($userSquadIds as $squadId) {
                if (isset($squadRoleMap[$squadId])) {
                    $targetRoleIds[] = $squadRoleMap[$squadId];
                }
            }
            $targetRoleIds = array_unique($targetRoleIds);
            
            // Remove inactive role from target roles if present
            if ($inactiveRoleId && in_array($inactiveRoleId, $targetRoleIds)) {
                $targetRoleIds = array_diff($targetRoleIds, [$inactiveRoleId]);
            }
            // Remove ignore roles from target roles
            if (!empty($ignoreRoleIds)) {
                $targetRoleIds = array_diff($targetRoleIds, $ignoreRoleIds);
            }

            // Get current role assignments (excluding inactive and ignore roles)
            $currentRoleIds = $user->roles->pluck('id')->toArray();
            if ($inactiveRoleId) {
                $currentRoleIds = array_diff($currentRoleIds, [$inactiveRoleId]);
            }
            if (!empty($ignoreRoleIds)) {
                $currentRoleIds = array_diff($currentRoleIds, $ignoreRoleIds);
            }

            // Calculate changes
            $rolesToAdd = array_diff($targetRoleIds, $currentRoleIds);
            $rolesToRemove = array_diff($currentRoleIds, $targetRoleIds);

            if (!empty($rolesToAdd) || !empty($rolesToRemove)) {
                $this->line("User: {$user->name} (ID: {$user->id})");
                
                if (!empty($rolesToAdd)) {
                    $roleNames = Role::whereIn('id', $rolesToAdd)->pluck('title', 'id');
                    foreach ($rolesToAdd as $roleId) {
                        if (!empty($ignoreRoleIds) && in_array($roleId, $ignoreRoleIds)) {
                            continue;
                        }
                        $this->info("  + Add role: {$roleNames[$roleId]} (ID: {$roleId})");
                        $totalChanges++;
                        
                        if (!$isDryRun) {
                            $user->roles()->attach($roleId);
                        }
                    }
                }

                if (!empty($rolesToRemove)) {
                    $roleNames = Role::whereIn('id', $rolesToRemove)->pluck('title', 'id');
                    foreach ($rolesToRemove as $roleId) {
                        if (!empty($ignoreRoleIds) && in_array($roleId, $ignoreRoleIds)) {
                            continue;
                        }
                        $this->warn("  - Remove role: {$roleNames[$roleId]} (ID: {$roleId})");
                        $totalChanges++;
                        
                        if (!$isDryRun) {
                            $user->roles()->detach($roleId);
                        }
                    }
                }
                
                $this->newLine();
            }
        });

        $this->newLine();
        $this->info("Synchronization complete!");
        $this->info("Total users processed: {$totalUsers}");
        $this->info("Total changes: {$totalChanges}");
        
        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No actual changes were made');
        }

        return 0;
    }
}
