<?php

declare(strict_types=1);

namespace Goemktg\Seat\SeatCustomPlugin\Http\Controllers;

use Goemktg\Seat\SeatCustomPlugin\Services\DoctrineSync\DoctrineSync;
use Goemktg\Seat\SeatCustomPlugin\Services\DoctrineSync\DoctrineSyncException;
use Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync\ImportInput;
use Goemktg\Seat\SeatCustomPlugin\Support\DoctrineSync\SyncAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Seat\Web\Http\Controllers\Controller;

/**
 * Import window and status of doctrines followed from the doctrine site.
 * Every action is for one corporation the route already authorised, and only
 * when Settings turned the import on for it.
 */
class DoctrineImportController extends Controller
{
    public function __construct(private readonly DoctrineSync $sync)
    {
    }

    /** Step 1 of the import window: the doctrines on the site. */
    public function choose(Request $request, CorporationInfo $corporation)
    {
        $this->assertEnabled($corporation);
        try {
            $doctrines = $this->sync->client()->doctrines();
        } catch (DoctrineSyncException $e) {
            return view('custom-plugin::import.error', ['message' => $e->getMessage()]);
        }
        $following = DB::table(DoctrineSync::SOURCES)->where('corporation_id', $corporation->corporation_id)
            ->where('following', true)->pluck('slug')->all();

        return view('custom-plugin::import.choose', [
            'corporation' => $corporation,
            'doctrines' => $doctrines,
            'following' => array_flip($following),
            // ticked again when the user comes Back from step 2
            'checked' => ImportInput::slugs($request->query('slugs')),
            'max' => ImportInput::MAX_DOCTRINES,
        ]);
    }

    /** Step 2: what importing the chosen doctrines would do. Writes nothing. */
    public function review(Request $request, CorporationInfo $corporation)
    {
        $this->assertEnabled($corporation);
        $slugs = ImportInput::slugs($request->query('slugs'));
        if ($slugs === []) {
            return view('custom-plugin::import.error', ['message' => 'Choose at least one doctrine to import.']);
        }

        $reviews = [];
        foreach ($slugs as $slug) {
            try {
                $preview = $this->sync->preview($corporation->corporation_id, $slug);
                $reviews[] = ['slug' => $slug, 'doctrine' => $preview['doctrine'], 'actions' => $preview['actions'], 'error' => null];
            } catch (DoctrineSyncException $e) {
                $reviews[] = ['slug' => $slug, 'doctrine' => null, 'actions' => [], 'error' => $e->getMessage()];
            }
        }

        return view('custom-plugin::import.review', ['corporation' => $corporation, 'reviews' => $reviews]);
    }

    /** Applies the import, linking the hand-made fits the user ticked. */
    public function store(Request $request, CorporationInfo $corporation)
    {
        $this->assertEnabled($corporation);
        $slugs = ImportInput::slugs($request->input('slugs'));
        if ($slugs === []) {
            return back()->withErrors(['import' => 'Choose at least one doctrine to import.']);
        }

        [$counts, $errors] = $this->applyAll($corporation, $slugs, (int) $request->user()->id, $request->input('link'));

        $imported = count($slugs) - count($errors);
        $done = sprintf('Imported %d %s: %s.', $imported, $imported === 1 ? 'doctrine' : 'doctrines', self::summary($counts));
        if (($counts[SyncAction::ADD] ?? 0) > 0) {
            $done .= ' New fits require no ships yet; set the counts on each fit\'s Edit page.';
        }
        $redirect = redirect()->route('custom-plugin.fits.index', $corporation->corporation_id)->with('success', $done);

        return $errors === [] ? $redirect : $redirect->withErrors($errors);
    }

    /** Brings every followed doctrine of the corporation in step now. */
    public function syncNow(Request $request, CorporationInfo $corporation)
    {
        $this->assertEnabled($corporation);
        $slugs = DB::table(DoctrineSync::SOURCES)->where('corporation_id', $corporation->corporation_id)
            ->where('following', true)->orderBy('name')->pluck('slug')->all();
        $redirect = redirect()->route('custom-plugin.fits.index', $corporation->corporation_id);
        if ($slugs === []) {
            return $redirect->withErrors(['import' => 'No doctrines are followed yet. Use Import from doctrine site first.']);
        }

        [$counts, $errors] = $this->applyAll($corporation, $slugs, (int) $request->user()->id, null);
        $redirect = $redirect->with('success', sprintf('Synced %d of %d followed doctrines: %s.', count($slugs) - count($errors), count($slugs), self::summary($counts)));

        return $errors === [] ? $redirect : $redirect->withErrors($errors);
    }

    /** Every change of the last 90 days. */
    public function history(CorporationInfo $corporation)
    {
        $this->assertEnabled($corporation);

        return view('custom-plugin::import.history', [
            'corporation' => $corporation,
            'events' => DB::table(DoctrineSync::EVENTS)->where('corporation_id', $corporation->corporation_id)
                ->orderByDesc('created_at')->orderByDesc('id')->paginate(50),
            'keepDays' => DoctrineSync::KEEP_DAYS,
        ]);
    }

    /**
     * @param  list<string>  $slugs
     * @return array{0: array<string, int>, 1: list<string>}
     */
    private function applyAll(CorporationInfo $corporation, array $slugs, int $userId, mixed $links): array
    {
        $counts = [];
        $errors = [];
        foreach ($slugs as $slug) {
            try {
                $result = $this->sync->apply($corporation->corporation_id, $slug, $userId, ImportInput::linkIds($links, $slug));
                foreach ($result['counts'] as $kind => $n) {
                    $counts[$kind] = ($counts[$kind] ?? 0) + $n;
                }
            } catch (DoctrineSyncException $e) {
                $errors[] = sprintf('%s: %s', $slug, $e->getMessage());
            }
        }

        return [$counts, $errors];
    }

    /** @param array<string, int> $counts */
    private static function summary(array $counts): string
    {
        $parts = [];
        foreach ([SyncAction::ADD => 'added', SyncAction::UPDATE => 'updated', SyncAction::LINK => 'linked',
            SyncAction::RETURNED => 'back on the site', SyncAction::TAKE_OVER => 'taken over', SyncAction::SHARED => 'shared',
            SyncAction::MISSING => 'gone from the site',
            SyncAction::HELD => 'held', SyncAction::FAILED => 'not readable'] as $kind => $label) {
            if (($counts[$kind] ?? 0) > 0) {
                $parts[] = sprintf('%d %s', $counts[$kind], $label);
            }
        }

        return $parts === [] ? 'no changes' : implode(', ', $parts);
    }

    private function assertEnabled(CorporationInfo $corporation): void
    {
        abort_unless($this->sync->enabledFor($corporation->corporation_id), 404);
    }
}
