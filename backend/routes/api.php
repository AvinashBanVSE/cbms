<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/v1/installation', static function (): JsonResponse {
    $stage = DB::table('portal_installation')->where('id', 1)->value('stage');
    abort_unless(in_array($stage, ['setup_required', 'owner_required', 'ready'], true), 503);

    return response()->json(['stage' => $stage])->header('Cache-Control', 'no-store');
});
