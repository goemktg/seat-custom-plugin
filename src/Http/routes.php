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

// Namespace all of the routes for this package.
Route::group([
    'namespace'  => 'Goemktg\\Seat\\SeatCustomPlugin\Http\Controllers',
    'middleware' => ['web', 'auth', 'locale'],
], function (): void {

    Route::group([
        'prefix'     => 'custom-plugin',
    ], function (): void {
        Route::group([
            'middleware' => 'can:global.superuser',
        ], function (): void {
            Route::get('/settings', [
                'as' => 'custom-plugin.settings',
                'uses' => 'SettingsController@index'
            ]);
            Route::post('/settings', [
                'as' => 'custom-plugin.settings.update',
                'uses' => 'SettingsController@update'
            ]);
        });

        // Hangar Check: access is decided per corporation inside the pages
        Route::get('/hangar-check', 'HangarCheckController@index')
            ->name('custom-plugin.hangar.index');
        Route::get('/hangar-check/{corporation}', 'HangarCheckController@show')
            ->name('custom-plugin.hangar.show')
            ->middleware('can:corporation.doctrine_hangar_view,corporation');

        // Doctrine fits and stagings
        Route::get('/doctrine-fits', 'DoctrineFitController@pick')
            ->name('custom-plugin.fits.pick');
        Route::group([
            'prefix' => '/doctrine-fits/{corporation}',
            'middleware' => 'can:corporation.doctrine_fit_manage,corporation',
        ], function (): void {
            Route::get('/', 'DoctrineFitController@index')->name('custom-plugin.fits.index');
            Route::get('/create', 'DoctrineFitController@create')->name('custom-plugin.fits.create');
            Route::post('/', 'DoctrineFitController@store')->name('custom-plugin.fits.store');
            Route::get('/{fit}/edit', 'DoctrineFitController@edit')->whereNumber('fit')->name('custom-plugin.fits.edit');
            Route::put('/{fit}', 'DoctrineFitController@update')->whereNumber('fit')->name('custom-plugin.fits.update');
            Route::delete('/{fit}', 'DoctrineFitController@destroy')->whereNumber('fit')->name('custom-plugin.fits.destroy');
            Route::post('/stagings', 'DoctrineFitController@storeStaging')->name('custom-plugin.stagings.store');
            Route::delete('/stagings/{staging}', 'DoctrineFitController@destroyStaging')->whereNumber('staging')->name('custom-plugin.stagings.destroy');
        });
    });
});
