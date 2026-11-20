<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\MainPortalAccess;
use App\Domain\ExternalLegalIdentityVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LegalIdentityController
{
    public function __construct(private readonly MainPortalAccess $access) {}

    public function store(Request $request): JsonResponse
    {
        $this->access->requirePermission('business_identity.verify');
        $data = $request->validate([
            'legal_name' => 'required|string|max:200',
            'evidence_reference' => 'required|string|max:200',
            'evidence_reviewed' => 'accepted',
        ]);

        return $this->persist($data['legal_name'], $data['evidence_reference'], 'manual');
    }

    public function verifyExternally(Request $request, ExternalLegalIdentityVerifier $verifier): JsonResponse
    {
        $this->access->requirePermission('business_identity.verify');
        $data = $request->validate(['legal_name' => 'required|string|max:200', 'evidence_reference' => 'required|string|max:200']);
        $verifier->verify($data['legal_name'], $data['evidence_reference']);
        $this->access->requirePermission('business_identity.verify');

        return $this->persist($data['legal_name'], $data['evidence_reference'], 'external');
    }

    private function persist(string $name, string $reference, string $source): JsonResponse
    {
        $id = Str::uuid7()->toString();
        DB::transaction(function () use ($name, $reference, $source, $id): void {
            DB::table('legal_identities')->insert([
                'id' => $id, 'legal_name' => $name, 'evidence_reference' => $reference,
                'verified_by' => Auth::id(), 'verified_at' => now(), 'verification_source' => $source,
            ]);
            $this->record('legal_identity.verified', $id, ['source' => $source]);
        });

        return response()->json(['id' => $id], 201)->header('Cache-Control', 'no-store');
    }

    public function attach(Request $request, string $business): JsonResponse
    {
        $this->access->requirePermission('business_identity.verify');
        $data = $request->validate(['legal_identity_id' => 'required|uuid']);

        return DB::transaction(function () use ($business, $data): JsonResponse {
            $target = DB::table('business_identities')->where('id', $business)->lockForUpdate()->first();
            abort_if($target === null, 404);
            abort_if($target->operational_state === 'Closed', 409);
            $legal = DB::table('legal_identities')->where('id', $data['legal_identity_id'])->first(['id', 'legal_name']);
            abort_if($legal === null, 404);
            $existing = DB::table('business_legal_identity_links')->where('business_id', $business)->value('legal_identity_id');
            abort_if($existing !== null && $existing !== $legal->id, 409);
            abort_if($target->legal_name !== null && mb_strtolower($target->legal_name) !== mb_strtolower($legal->legal_name), 409, 'The recorded legal name differs from the verified record.');
            if ($existing === null) {
                DB::table('business_legal_identity_links')->insert(['business_id' => $business, 'legal_identity_id' => $legal->id]);
                $this->record('legal_identity.associated', $business, ['legal_identity_id' => $legal->id]);
            }

            return response()->json(['legal_identity_id' => $legal->id])->header('Cache-Control', 'no-store');
        }, attempts: 3);
    }

    private function record(string $action, string $subject, array $details): void
    {
        DB::table('portal_events')->insert([
            'id' => Str::uuid7()->toString(), 'action' => $action, 'actor_id' => Auth::id(),
            'subject_id' => $subject, 'recorded_at' => now(), 'details' => json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }
}
