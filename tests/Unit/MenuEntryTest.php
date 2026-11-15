<?php

namespace Goemktg\Seat\SeatCustomPlugin\Tests\Unit;

use Goemktg\Seat\SeatCustomPlugin\Support\MenuEntry;
use PHPUnit\Framework\TestCase;

final class MenuEntryTest extends TestCase
{
    public function testHangarCheckPagesBelongToTheHangarCheckEntry(): void
    {
        foreach (['custom-plugin.hangar.index', 'custom-plugin.hangar.show'] as $route) {
            $this->assertSame('custom-plugin.hangar.index', MenuEntry::for($route), $route);
        }
    }

    public function testDoctrineFitAndStagingPagesBelongToTheDoctrineFitsEntry(): void
    {
        foreach ([
            'custom-plugin.fits.pick',
            'custom-plugin.fits.index',
            'custom-plugin.fits.create',
            'custom-plugin.fits.edit',
            'custom-plugin.stagings.store',
        ] as $route) {
            $this->assertSame('custom-plugin.fits.pick', MenuEntry::for($route), $route);
        }
    }

    public function testSettingsBelongsToTheSettingsEntry(): void
    {
        $this->assertSame('custom-plugin.settings', MenuEntry::for('custom-plugin.settings'));
    }

    public function testRoutesOutsideThePluginHaveNoEntry(): void
    {
        foreach ([null, '', 'home', 'custom-plugin', 'custom-plugin.hangarx.show', 'other.custom-plugin.hangar.show'] as $route) {
            $this->assertNull(MenuEntry::for($route), var_export($route, true));
        }
    }
}
