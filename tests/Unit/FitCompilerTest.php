<?php

namespace Goemktg\Seat\SeatCustomPlugin\Tests\Unit;

use Goemktg\Seat\SeatCustomPlugin\Conformance\FitCompiler;
use PHPUnit\Framework\TestCase;

final class FitCompilerTest extends TestCase
{
    private static function lookup(): \Closure
    {
        $types = [
            'damage control ii' => [2048, 'Damage Control II'],
            'cap recharger ii' => [2032, 'Cap Recharger II'],
            'nanite repair paste' => [28668, 'Nanite Repair Paste'],
        ];

        return static function (array $names) use ($types): array {
            $out = [];
            foreach ($names as $name) {
                $key = mb_strtolower($name);
                if (isset($types[$key])) {
                    $out[$key] = ['id' => $types[$key][0], 'name' => $types[$key][1]];
                }
            }
            return $out;
        };
    }

    public function testCompilesSlotsCargoBaysAndContainer(): void
    {
        $fit = FitCompiler::compile(
            id: 7,
            doctrine: 'Archon',
            name: 'Archon Logi',
            shipTypeId: 23757,
            order: 2,
            eft: "[Archon, Archon Logi]\ndamage control ii\n\nCap Recharger II x2\n\n[Empty High slot]\n\n[Empty Rig slot]\n\nNanite Repair Paste x50\n",
            bays: ['FuelBay' => [['name' => 'Helium Isotopes', 'qty' => 1000, 'typeId' => 16274]]],
            container: [['name' => 'Cap Recharger II', 'qty' => 4, 'typeId' => 2032]],
            lookup: self::lookup(),
        );

        $this->assertSame(7, $fit->id);
        $this->assertSame([2048 => 1], $fit->slots['low']);
        $this->assertSame([2032 => 2], $fit->slots['mid']);
        $this->assertSame([], $fit->slots['high']);
        $this->assertSame([28668 => 50], $fit->cargo);
        $this->assertSame(['FuelBay' => [16274 => 1000]], $fit->bays);
        $this->assertSame([2032 => 4], $fit->container);
        $this->assertSame('Damage Control II', $fit->names[2048]);
        $this->assertSame('Helium Isotopes', $fit->names[16274]);
        $this->assertSame([], $fit->unresolved);
        $this->assertSame(50 + 1000 + 4, $fit->requirementCount());
    }

    public function testUnknownSlotModulesAndCargoLinesAreUnresolved(): void
    {
        $fit = FitCompiler::compile(
            id: 1, doctrine: 'X', name: 'X', shipTypeId: 1, order: 0,
            eft: "[Archon, X]\nTypo Module II\n\n[Empty Med slot]\n\n[Empty High slot]\n\n[Empty Rig slot]\n\nMystery Box x3\n",
            bays: [], container: [], lookup: self::lookup(),
        );

        $this->assertSame([], $fit->slots['low']);
        $this->assertSame(['Mystery Box x3', 'Typo Module II'], $fit->unresolved);
    }
}
