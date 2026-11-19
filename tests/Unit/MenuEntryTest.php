<?php

declare(strict_types=1);

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

    public function testEverySidebarEntryLightsUpOnItsOwnRoute(): void
    {
        $routes = [];
        foreach ($this->sidebar() as $group) {
            $this->assertNotEmpty($group['entries']);
            foreach ($group['entries'] as $entry) {
                $this->assertSame($entry['route'], MenuEntry::for($entry['route']), $entry['route']);
                $routes[] = $entry['route'];
            }
        }

        $this->assertCount(3, $routes);
    }

    public function testTheCustomPluginGroupOnlyHoldsSettings(): void
    {
        $this->assertSame(
            ['custom-plugin.settings'],
            array_column($this->sidebar()['custom-plugin']['entries'], 'route'),
        );
    }

    public function testHangarCheckAndDoctrineFitsSitInTheInventoryGroup(): void
    {
        $this->assertSame(
            ['custom-plugin.hangar.index', 'custom-plugin.fits.pick'],
            array_column($this->sidebar()['custom-plugin-inventory']['entries'], 'route'),
        );
    }

    /**
     * SeAT opens a sidebar group when the first URL segment equals its
     * route_segment, so two groups sharing one would open together.
     */
    public function testEachGroupOpensOnItsOwnUrlSegment(): void
    {
        $segments = array_column($this->sidebar(), 'route_segment');

        $this->assertSame(['custom-plugin', 'inventory'], $segments);
    }

    public function testAGroupIsHiddenWhenNoneOfItsEntriesIsVisible(): void
    {
        foreach ($this->sidebar() as $key => $group) {
            $needed = array_unique(array_column($group['entries'], 'permission'));
            sort($needed);
            $granted = (array) $group['permission'];
            sort($granted);

            $this->assertSame($needed, $granted, $key);
        }
    }

    public function testRoutesOutsideThePluginHaveNoEntry(): void
    {
        foreach ([null, '', 'home', 'custom-plugin', 'custom-plugin.hangarx.show', 'other.custom-plugin.hangar.show'] as $route) {
            $this->assertNull(MenuEntry::for($route), var_export($route, true));
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function sidebar(): array
    {
        return require __DIR__ . '/../../src/Config/package.sidebar.php';
    }
}
