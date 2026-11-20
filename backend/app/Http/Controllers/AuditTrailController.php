<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\MainPortalAccess;
use App\Configuration\BusinessTimePresentation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AuditTrailController
{
    public function __invoke(Request $request, MainPortalAccess $access, BusinessTimePresentation $presentation): JsonResponse
    {
        $access->requirePermission('audit_trail.view');
        $input = $request->validate([
            'action' => 'sometimes|required|string|max:120|regex:/^[a-z][a-z0-9_.]*$/D',
            'subject_id' => 'sometimes|required|uuid',
            'before_id' => 'sometimes|required|uuid',
        ]);
        $query = DB::table('portal_events');
        foreach (['action', 'subject_id'] as $field) {
            if (isset($input[$field])) {
                $query->where($field, $input[$field]);
            }
        }
        if (isset($input['before_id'])) {
            $anchor = $query->clone()->where('id', $input['before_id'])->first(['id', 'recorded_at']);
            abort_if($anchor === null, 404);
            $query->where(function (Builder $events) use ($anchor): void {
                $events->where('recorded_at', '<', $anchor->recorded_at)->orWhere(function (Builder $sameTime) use ($anchor): void {
                    $sameTime->where('recorded_at', $anchor->recorded_at)->where('id', '<', $anchor->id);
                });
            });
        }

        $results = $query->orderByDesc('recorded_at')->orderByDesc('id')->limit(31)
            ->get(['id', 'action', 'actor_id', 'subject_id', 'recorded_at']);
        $page = $results->take(30)->map(static function (object $event): object {
            $event->recorded_at = CarbonImmutable::parse($event->recorded_at, 'UTC')->utc()->toISOString();

            return $event;
        });

        DB::table('portal_events')->insert([
            'id' => Str::uuid7()->toString(), 'action' => 'audit_trail.viewed', 'actor_id' => Auth::id(),
            'subject_id' => $input['subject_id'] ?? null, 'recorded_at' => now(),
            'details' => json_encode($input, JSON_THROW_ON_ERROR),
        ]);

        $portal = DB::table('portal_installation')->where('id', 1)->value('portal_id');

        return response()->json([
            'data' => $page, 'next_before_id' => $results->count() > 30 ? $page->last()->id : null,
            'presentation' => [
                'clock' => $presentation->resolve($portal, $portal)['effective']['clock'],
                'date_format' => $presentation->resolve($portal, $portal, 'date_format')['effective']['date_format'],
            ],
        ])->header('Cache-Control', 'no-store');
    }
}
