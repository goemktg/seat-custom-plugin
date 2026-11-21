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
            Route::post('/settings/doctrine-sync', [
                'as' => 'custom-plugin.settings.doctrine-sync',
                'uses' => 'SettingsController@updateDoctrineSync'
            ]);
        });

        // Sign-in with SeAT for the registered applications; a signed-out user logs in first and comes back
        // limited per application and user in OAuthController, not by a per-user throttle middleware
        Route::get('/oauth/authorize', 'OAuthController@authorizeRequest')
            ->name('custom-plugin.oauth.authorize');
    });

    // Inventory pages live under their own first URL segment so the sidebar
    // opens the Inventory group for them and not the Custom Plugin one.
    Route::group([
        'prefix'     => 'inventory',
    ], function (): void {
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
            'middleware' => 'can:corporation.doctrine_hangar_view,corporation',
        ], function (): void {
            Route::get('/', 'DoctrineFitController@index')->name('custom-plugin.fits.index');
            Route::get('/create', 'DoctrineFitController@create')->name('custom-plugin.fits.create');
            Route::post('/', 'DoctrineFitController@store')->name('custom-plugin.fits.store');
            Route::get('/{fit}/edit', 'DoctrineFitController@edit')->whereNumber('fit')->name('custom-plugin.fits.edit');
            Route::put('/{fit}', 'DoctrineFitController@update')->whereNumber('fit')->name('custom-plugin.fits.update');
            Route::delete('/{fit}', 'DoctrineFitController@destroy')->whereNumber('fit')->name('custom-plugin.fits.destroy');
            Route::post('/{fit}/move', 'DoctrineFitController@move')->whereNumber('fit')->name('custom-plugin.fits.move');
            Route::post('/stagings', 'DoctrineFitController@storeStaging')->name('custom-plugin.stagings.store');
            Route::delete('/stagings/{staging}', 'DoctrineFitController@destroyStaging')->whereNumber('staging')->name('custom-plugin.stagings.destroy');

            // Stock ledger per fit and staging, opened from Hangar Check
            Route::get('/stock/{staging}/{fit}', 'StockController@show')->whereNumber(['staging', 'fit'])->name('custom-plugin.stock.show');
            Route::post('/stock/{staging}/{fit}', 'StockController@store')->whereNumber(['staging', 'fit'])->name('custom-plugin.stock.store');
            Route::delete('/stock/entries/{entry}', 'StockController@destroy')->whereNumber('entry')->name('custom-plugin.stock.destroy');

            // Doctrines followed from the doctrine site; each of these but history asks the site
            Route::get('/import/history', 'DoctrineImportController@history')->name('custom-plugin.fits.import.history');
            Route::group(['middleware' => 'throttle:20,1'], function (): void {
                Route::get('/import/choose', 'DoctrineImportController@choose')->name('custom-plugin.fits.import.choose');
                Route::get('/import/review', 'DoctrineImportController@review')->name('custom-plugin.fits.import.review');
                Route::post('/import', 'DoctrineImportController@store')->name('custom-plugin.fits.import.store');
                Route::post('/import/sync', 'DoctrineImportController@syncNow')->name('custom-plugin.fits.import.sync');
            });
        });
    });

    // These pages used to live under /custom-plugin; keep old links working.
    Route::get('/custom-plugin/{page}/{rest?}', static function (string $page, ?string $rest = null) {
        // the router hands over decoded segments; encode them again so a "?" or "#" stays part of the path
        $path = implode('/', array_map('rawurlencode', explode('/', $page . ($rest === null ? '' : '/' . $rest))));
        $query = request()->getQueryString();

        return redirect('/inventory/' . $path . ($query === null ? '' : '?' . $query));
    })->whereIn('page', ['hangar-check', 'doctrine-fits'])->where('rest', '.*');
});

// Server-to-server OAuth endpoints for the registered applications: no session, no CSRF,
// no cookies. The client proves itself with its secret or a bearer token.
// Their limits are counted in OAuthController (failed requests per address,
// the rest per application and user), not by a per-address throttle middleware.
Route::group([
    'namespace'  => 'Goemktg\\Seat\\SeatCustomPlugin\Http\Controllers',
    'prefix'     => 'custom-plugin/oauth',
], function (): void {
    Route::post('/token', 'OAuthController@token')
        ->name('custom-plugin.oauth.token');
    Route::get('/userinfo', 'OAuthController@userinfo')
        ->name('custom-plugin.oauth.userinfo');
});
