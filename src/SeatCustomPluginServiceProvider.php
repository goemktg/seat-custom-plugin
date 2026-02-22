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

namespace Goemktg\Seat\SeatCustomPlugin;

use Seat\Services\AbstractSeatPlugin;
use Goemktg\Seat\SeatCustomPlugin\Commands\SyncSquadsWithRoles;

/**
 * Class SeatCustomPluginServiceProvider.
 *
 * @package Goemktg\\Seat\\SeatCustomPlugin
 */
class SeatCustomPluginServiceProvider extends AbstractSeatPlugin
{
    public function boot()
    {
        $this->add_commands();
        
        $this->add_routes();

        $this->add_publications();

        $this->add_views();

        $this->add_translations();

        $this->add_migrations();
    }

    public function register()
    {
        // $this->mergeConfigFrom(__DIR__ . '/Config/mumble.config.php', 'mumble.config');
        $this->mergeConfigFrom(__DIR__ . '/Config/custom-plugin.locale.php', 'custom-plugin.locale');

        // Overload sidebar with your package menu entries
        $this->mergeConfigFrom(__DIR__ . '/Config/package.sidebar.php', 'package.sidebar');

        // Register generic permissions
        $this->registerPermissions(__DIR__ . '/Config/custom-plugin.permissions.php', 'custom-plugin');
    }

    /**
     * Register cli commands.
     */
    private function add_commands()
    {
        $this->commands([
            SyncSquadsWithRoles::class,
        ]);
    }

    /**
     * Include routes.
     */
    private function add_routes()
    {
        $this->loadRoutesFrom(__DIR__ . '/Http/routes.php');
    }

    /**
     * Add content which must be published (generally, configuration files or static ones).
     */
    private function add_publications()
    {
        $this->publishes(['public', 'seat']);
    }

    /**
     * Import translations.
     */
    private function add_translations()
    {
        $this->loadTranslationsFrom(__DIR__ . '/resources/lang', 'custom-plugin');
    }

    /**
     * Import views.
     */
    private function add_views()
    {
        $this->loadViewsFrom(__DIR__ . '/resources/views', 'custom-plugin');
    }

    /**
     * Import database migrations.
     */
    private function add_migrations()
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations/');
    }

    /**
     * Return the plugin public name as it should be displayed into settings.
     *
     * @return string
     * @example SeAT Web
     *
     */
    public function getName(): string
    {
        return 'SeAT Custom Plugin';
    }

    /**
     * Return the plugin repository address.
     *
     * @example https://github.com/eveseat/web
     *
     * @return string
     */
    public function getPackageRepositoryUrl(): string
    {
        return 'https://github.com/goemktg/seat-custom-plugin';
    }

    /**
     * Return the plugin technical name as published on package manager.
     *
     * @return string
     * @example web
     *
     */
    public function getPackagistPackageName(): string
    {
        return 'seat-custom-plugin';
    }

    /**
     * Return the plugin vendor tag as published on package manager.
     *
     * @return string
     * @example eveseat
     *
     */
    public function getPackagistVendorName(): string
    {
        return 'goemktg';
    }

}
