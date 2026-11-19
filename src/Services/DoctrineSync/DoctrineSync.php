<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Services\DoctrineSync;

use Goemktg\Seat\SeatCustomPlugin\Conformance\FitCompiler;
use Goemktg\Seat\SeatCustomPlugin\Models\DoctrineFit;
use Goemktg\Seat\SeatCustomPlugin\Services\SdeTypes;
use Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync\SyncAction;
use Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync\SyncPlanner;
use Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync\UpstreamDoctrine;
use Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync\UpstreamFit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Follows doctrines of the doctrine site for a corporation: previews what a
 * sync would do and applies it. Only a fit's doctrine, name, hull and EFT come
 * from upstream; required counts, bays, container and the stock ledger are
 * never touched, and nothing is ever deleted.
 */
class DoctrineSync
{
    public const SOURCES = 'custom_plugin_doctrine_sources';

    public const EVENTS = 'custom_plugin_doctrine_sync_events';

    /** global setting: JSON list of corporation ids that may follow doctrines */
    public const SETTING = 'custom-plugin.doctrine_sync_corporations';

    public const KEEP_DAYS = 90;

    private const LOCK_SECONDS = 300;

    public function __construct(private readonly DoctrineClient $client, private readonly SdeTypes $sde)
    {
    }

    /** @return list<int> */
    public static function corporations(): array
    {
        $ids = json_decode((string) setting(self::SETTING, true), true);

        return is_array($ids) ? array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric')))) : [];
    }

    public function enabledFor(int $corporationId): bool
    {
        return $this->client->configured() && in_array($corporationId, self::corporations(), true);
    }

    public function client(): DoctrineClient
    {
        return $this->client;
    }

    /**
     * What a sync would do now. Writes nothing.
     *
     * @param  list<int>  $linkIds  manual fits the user chose to hand over
     * @return array{doctrine: UpstreamDoctrine, sourceId: ?int, actions: list<SyncAction>, current: array<int, string>}
     *         current: fit id => its EFT now, for the fits the plan would change
     *
     * @throws DoctrineSyncException
     */
    public function preview(int $corporationId, string $slug, array $linkIds = []): array
    {
        $this->assertEnabled($corporationId);
        $doctrine = $this->fetch($slug);
        $sourceId = $this->sourceId($corporationId, $slug);
        $actions = $this->plan($corporationId, $doctrine, $sourceId, $linkIds);
        $ids = array_values(array_filter(array_map(static fn (SyncAction $a) => $a->writes() ? $a->fitId : null, $actions)));
        $current = $ids === [] ? [] : DoctrineFit::whereIn('id', $ids)->pluck('eft', 'id')->all();

        return ['doctrine' => $doctrine, 'sourceId' => $sourceId, 'actions' => $actions, 'current' => $current];
    }

    /**
     * Follows the doctrine (again) and brings its fits in step.
     *
     * @param  ?int  $userId  null for the hourly sync
     * @param  list<int>  $linkIds  manual fits the user chose to hand over
     * @return array{runId: string, counts: array<string, int>, actions: list<SyncAction>}
     *
     * @throws DoctrineSyncException
     */
    public function apply(int $corporationId, string $slug, ?int $userId, array $linkIds = []): array
    {
        $this->assertEnabled($corporationId);
        $lock = Cache::lock(sprintf('custom-plugin:doctrine-sync:%d:%s', $corporationId, $slug), self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw new DoctrineSyncException('This doctrine is being synced right now. Try again in a minute.');
        }
        try {
            try {
                $doctrine = $this->fetch($slug);
            } catch (DoctrineSyncException $e) {
                $this->recordFailure($corporationId, $slug, $userId, $e->getMessage());
                throw $e;
            }

            return DB::transaction(fn () => $this->write($corporationId, $doctrine, $userId, $linkIds));
        } finally {
            $lock->release();
        }
    }

    /** Stops updating the doctrine's fits. They stay, as they are. */
    public function unfollow(int $corporationId, int $sourceId): void
    {
        DB::table(self::SOURCES)->where('corporation_id', $corporationId)->where('id', $sourceId)
            ->update(['following' => false, 'updated_at' => now()]);
    }

    /** Deletes history older than KEEP_DAYS, a batch at a time. */
    public static function prune(): int
    {
        $before = now()->subDays(self::KEEP_DAYS);
        $deleted = 0;
        do {
            $n = DB::table(self::EVENTS)->where('created_at', '<', $before)->orderBy('id')->limit(1000)->delete();
            $deleted += $n;
        } while ($n === 1000);

        return $deleted;
    }

    private function assertEnabled(int $corporationId): void
    {
        if (! $this->enabledFor($corporationId)) {
            throw new DoctrineSyncException('Doctrine import is not turned on for this corporation.');
        }
    }

    private function fetch(string $slug): UpstreamDoctrine
    {
        try {
            $doctrine = UpstreamDoctrine::fromResponse($this->client->doctrine($slug));
        } catch (\InvalidArgumentException) {
            throw new DoctrineSyncException('The doctrine site sent an answer this plugin cannot read.');
        }
        if ($doctrine->slug !== $slug) {
            throw new DoctrineSyncException('The doctrine site sent a different doctrine than asked for.');
        }

        return $doctrine;
    }

    private function sourceId(int $corporationId, string $slug): ?int
    {
        $id = DB::table(self::SOURCES)->where('corporation_id', $corporationId)->where('slug', $slug)->value('id');

        return $id === null ? null : (int) $id;
    }

    /** @param list<int> $linkIds */
    private function plan(int $corporationId, UpstreamDoctrine $doctrine, ?int $sourceId, array $linkIds): array
    {
        $linked = [];
        if ($sourceId !== null) {
            $rows = DoctrineFit::where('corporation_id', $corporationId)->where('source_id', $sourceId)
                ->get(['id', 'fit_name', 'ship_type_id', 'sync_state']);
            $hulls = DB::table('invTypes')->whereIn('typeID', $rows->pluck('ship_type_id'))->pluck('typeName', 'typeID');
            foreach ($rows as $row) {
                $linked[] = [
                    'id' => (int) $row->id,
                    'label' => sprintf('%s (%s)', $row->fit_name, $hulls[$row->ship_type_id] ?? $row->ship_type_id),
                    'missing' => $row->sync_state === 'missing',
                ];
            }
        }

        $lookup = fn (array $names) => $this->sde->lookup($names);
        $resolve = function (UpstreamFit $fit) use ($lookup): array {
            $hull = $lookup([$fit->shipName])[mb_strtolower($fit->shipName)] ?? null;
            if ($hull === null || $this->sde->shipTypeIds([(int) $hull['id']]) === []) {
                return ['shipTypeId' => null, 'unresolved' => []];
            }
            $compiled = FitCompiler::compile(0, '', '', (int) $hull['id'], 0, $fit->eft, [], [], $lookup);

            return ['shipTypeId' => (int) $hull['id'], 'unresolved' => array_values($compiled->unresolved)];
        };
        // the database compares names, exactly as the two unique keys will
        $find = function (int $shipTypeId, string $fitName, string $doctrineName) use ($corporationId): ?array {
            $row = DoctrineFit::where('corporation_id', $corporationId)->where('ship_type_id', $shipTypeId)->where('fit_name', $fitName)->first()
                ?? DoctrineFit::where('corporation_id', $corporationId)->where('doctrine', $doctrineName)->where('fit_name', $fitName)->first();

            return $row === null ? null : [
                'id' => (int) $row->id,
                'sourceId' => $row->source_id === null ? null : (int) $row->source_id,
                'eft' => (string) $row->eft,
                'eftHash' => $row->source_eft_hash,
                'missing' => $row->sync_state === 'missing',
                'sameHull' => (int) $row->ship_type_id === $shipTypeId,
            ];
        };

        return SyncPlanner::plan($doctrine, $sourceId, $linked, $resolve, $find, array_map('intval', $linkIds));
    }

    /** @param list<int> $linkIds */
    private function write(int $corporationId, UpstreamDoctrine $doctrine, ?int $userId, array $linkIds): array
    {
        $now = now();
        $source = DB::table(self::SOURCES)->where('corporation_id', $corporationId)->where('slug', $doctrine->slug)
            ->lockForUpdate()->first();
        if ($source === null) {
            $sourceId = (int) DB::table(self::SOURCES)->insertGetId([
                'corporation_id' => $corporationId, 'slug' => $doctrine->slug, 'name' => $doctrine->name,
                'following' => true, 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } else {
            $sourceId = (int) $source->id;
        }

        $actions = $this->plan($corporationId, $doctrine, $sourceId, $linkIds);
        $done = [];
        foreach ($actions as $action) {
            $done[] = $this->writeOne($action, $corporationId, $sourceId, $doctrine, $userId, $now);
        }

        $runId = (string) Str::ulid();
        $userName = $userId === null ? null : DB::table('users')->where('id', $userId)->value('name');
        $events = [];
        $report = ['held' => [], 'failed' => []];
        $counts = [];
        foreach ($done as $action) {
            $counts[$action->kind] = ($counts[$action->kind] ?? 0) + 1;
            if ($action->kind === SyncAction::HELD || $action->kind === SyncAction::FAILED) {
                $report[$action->kind][] = ['fit' => $action->label, 'detail' => (string) $action->detail];
            }
            if ($action->kind === SyncAction::UNCHANGED) {
                continue;
            }
            $events[] = [
                'run_id' => $runId, 'corporation_id' => $corporationId, 'source_id' => $sourceId,
                'fit_id' => $action->fitId, 'kind' => $action->kind,
                'doctrine_label' => mb_substr($doctrine->name, 0, 100), 'fit_label' => mb_substr($action->label, 0, 255),
                'detail' => $action->detail === null ? null : mb_substr($action->detail, 0, 500),
                'created_by' => $userId, 'created_by_name' => $userName, 'created_at' => $now,
            ];
        }
        foreach (array_chunk($events, 200) as $chunk) {
            DB::table(self::EVENTS)->insert($chunk);
        }

        $changed = array_filter($done, static fn (SyncAction $a) => $a->writes() || $a->kind === SyncAction::MISSING) !== [];
        DB::table(self::SOURCES)->where('id', $sourceId)->update(array_filter([
            'name' => $doctrine->name,
            'following' => true,
            'upstream_status' => $doctrine->status,
            'upstream_version' => $doctrine->version,
            'last_checked_at' => $now,
            'last_changed_at' => $changed ? $now : null,
            'last_report' => json_encode($report, JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
        ], static fn ($v) => $v !== null) + ['last_error' => null]);

        return ['runId' => $runId, 'counts' => $counts, 'actions' => $done];
    }

    /** Applies one planned action; a unique-key race turns it into a held fit. */
    private function writeOne(SyncAction $action, int $corporationId, int $sourceId, UpstreamDoctrine $doctrine, ?int $userId, $now): SyncAction
    {
        $fit = $action->fit;
        try {
            DB::transaction(function () use ($action, $fit, $corporationId, $sourceId, $doctrine, $userId, $now): void {
                $linkedValues = fn () => [
                    'doctrine' => $doctrine->name,
                    'eft' => $fit->eft,
                    'source_id' => $sourceId,
                    'sync_state' => null,
                    'source_missing_at' => null,
                    'source_eft_hash' => UpstreamFit::hash($fit->eft),
                    'source_synced_at' => $now,
                ];
                switch ($action->kind) {
                    case SyncAction::ADD:
                        DoctrineFit::create($linkedValues() + [
                            'corporation_id' => $corporationId, 'fit_name' => $fit->fitName,
                            'ship_type_id' => $action->shipTypeId, 'sort_order' => 0,
                            'created_by' => $userId, 'updated_by' => $userId,
                        ]);
                        break;
                    case SyncAction::UPDATE:
                    case SyncAction::RETURNED:
                    case SyncAction::LINK:
                        $row = DoctrineFit::where('corporation_id', $corporationId)->whereKey($action->fitId)->lockForUpdate()->firstOrFail();
                        $row->fill($linkedValues() + ($userId === null ? [] : ['updated_by' => $userId]))->save();
                        break;
                    case SyncAction::MISSING:
                        DoctrineFit::where('corporation_id', $corporationId)->whereKey($action->fitId)
                            ->update(['sync_state' => 'missing', 'source_missing_at' => $now]);
                        break;
                    case SyncAction::UNCHANGED:
                        // follows a doctrine renamed upstream
                        DoctrineFit::where('corporation_id', $corporationId)->whereKey($action->fitId)
                            ->where('doctrine', '!=', $doctrine->name)->update(['doctrine' => $doctrine->name]);
                        break;
                }
            });
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }

            return new SyncAction(SyncAction::HELD, $action->label, $fit, $action->shipTypeId, $action->fitId,
                'Another fit already has this name in this doctrine or on this hull.');
        }
        if ($action->kind === SyncAction::ADD) {
            $id = DoctrineFit::where('corporation_id', $corporationId)->where('source_id', $sourceId)
                ->where('ship_type_id', $action->shipTypeId)->where('fit_name', $fit->fitName)->value('id');

            return new SyncAction($action->kind, $action->label, $fit, $action->shipTypeId, $id === null ? null : (int) $id);
        }

        return $action;
    }

    private function recordFailure(int $corporationId, string $slug, ?int $userId, string $message): void
    {
        $source = DB::table(self::SOURCES)->where('corporation_id', $corporationId)->where('slug', $slug)->first();
        if ($source === null) {
            return;
        }
        $now = now();
        DB::table(self::SOURCES)->where('id', $source->id)->update([
            'last_checked_at' => $now, 'last_error' => mb_substr($message, 0, 255), 'updated_at' => $now,
        ]);
        // once per new error, not every hour
        if ($source->last_error !== $message) {
            DB::table(self::EVENTS)->insert([
                'run_id' => (string) Str::ulid(), 'corporation_id' => $corporationId, 'source_id' => $source->id,
                'fit_id' => null, 'kind' => SyncAction::FAILED, 'doctrine_label' => mb_substr((string) $source->name, 0, 100),
                'fit_label' => null, 'detail' => mb_substr($message, 0, 500), 'created_by' => $userId,
                'created_by_name' => $userId === null ? null : DB::table('users')->where('id', $userId)->value('name'),
                'created_at' => $now,
            ]);
        }
    }
}
