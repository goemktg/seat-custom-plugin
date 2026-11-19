<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Commands;

use Goemktg\Seat\SeatCustomPlugin\Services\DoctrineSync\DoctrineSync;
use Goemktg\Seat\SeatCustomPlugin\Services\DoctrineSync\DoctrineSyncException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The hourly sync: every followed doctrine of every corporation that may
 * follow doctrines, then the history older than 90 days.
 */
class SyncFollowedDoctrines extends Command
{
    protected $signature = 'custom-plugin:doctrines:sync';

    protected $description = 'Bring fits of followed doctrine-site doctrines in step and prune old sync history';

    public function handle(DoctrineSync $sync): int
    {
        $corporations = DoctrineSync::corporations();
        if (! $sync->client()->configured() || $corporations === []) {
            $this->line('Doctrine import is off.');
            DoctrineSync::prune();

            return self::SUCCESS;
        }

        $sources = DB::table(DoctrineSync::SOURCES)->where('following', true)
            ->whereIn('corporation_id', $corporations)->orderBy('id')->get(['corporation_id', 'slug']);
        $failed = 0;
        foreach ($sources as $source) {
            try {
                $result = $sync->apply((int) $source->corporation_id, (string) $source->slug, null);
                $counts = array_map(static fn ($k, $n) => "$k $n", array_keys($result['counts']), $result['counts']);
                $this->line(sprintf('%d %s: %s', $source->corporation_id, $source->slug, implode(', ', $counts) ?: 'no fits'));
            } catch (DoctrineSyncException $e) {
                $failed++;
                $this->warn(sprintf('%d %s: %s', $source->corporation_id, $source->slug, $e->getMessage()));
            }
        }
        $pruned = DoctrineSync::prune();
        if ($pruned > 0) {
            $this->line(sprintf('Pruned %d history rows.', $pruned));
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
