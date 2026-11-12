<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Conformance;

final class FitCompiler
{
    /**
     * @param  array<string, list<array{name: string, qty: int, typeId: int}>>  $bays
     * @param  list<array{name: string, qty: int, typeId: int}>  $container
     * @param  callable(list<string>): array<string, array{id: int, name: string}>  $lookup
     */
    public static function compile(
        int $id,
        string $doctrine,
        string $name,
        int $shipTypeId,
        int $order,
        string $eft,
        array $bays,
        array $container,
        callable $lookup,
    ): CompiledFit {
        $parsed = EftParser::parse($eft);
        $found = $lookup($parsed->slotModuleNames());

        $names = [];
        $unresolved = [];
        $slots = array_fill_keys(EftParser::SLOT_SECTIONS, []);
        foreach ($parsed->slots as $section => $modules) {
            foreach ($modules as $moduleName => $count) {
                $hit = $found[mb_strtolower((string) $moduleName)] ?? null;
                if ($hit === null) {
                    $unresolved[(string) $moduleName] = true;
                    continue;
                }
                $typeId = (int) $hit['id'];
                $slots[$section][$typeId] = ($slots[$section][$typeId] ?? 0) + $count;
                $names[$typeId] = (string) $hit['name'];
            }
        }

        $cargo = QuantityLines::resolve($parsed->cargoLines, $lookup);
        $names += $cargo->names;
        foreach ($cargo->unresolved as $line) {
            $unresolved[$line] = true;
        }

        $bayCounts = [];
        foreach ($bays as $bay => $items) {
            foreach ($items as $item) {
                $typeId = (int) $item['typeId'];
                $bayCounts[$bay][$typeId] = ($bayCounts[$bay][$typeId] ?? 0) + (int) $item['qty'];
                $names[$typeId] ??= (string) $item['name'];
            }
        }

        $containerCounts = [];
        foreach ($container as $item) {
            $typeId = (int) $item['typeId'];
            $containerCounts[$typeId] = ($containerCounts[$typeId] ?? 0) + (int) $item['qty'];
            $names[$typeId] ??= (string) $item['name'];
        }

        $unresolvedList = array_map('strval', array_keys($unresolved));
        sort($unresolvedList);

        return new CompiledFit(
            $id, $doctrine, $name, $shipTypeId, $order,
            $slots, $cargo->counts, $containerCounts, $bayCounts, $names, $unresolvedList,
        );
    }
}
