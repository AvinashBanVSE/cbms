<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mapping-request integrity rules (§4.3, §4.4.1, §4.4.3).
 *
 * - request_expires_at: the Pending request's own configurable validity, separate from the
 *   per-code expires_at used for the two verification stages.
 * - rejected_at: marks the final Rejected request; it is never reopened, though a fresh
 *   request for the same pair is still allowed once no live mapping remains.
 * - initiated_by: which endpoint kind opened the request (Shop or Distributor). Required to
 *   resolve the Initiator/Recipient role at a given pending stage, because Reject (Recipient)
 *   and Withdraw (Initiator) are both taken while the request sits in the same state.
 * - partial unique index on (shop_id, distributor_id) for the live states enforces the
 *   duplicate-pair ban at the database level even under concurrent requests from both sides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distributor_shop_mappings', function (Blueprint $table): void {
            $table->string('initiated_by')->default('Shop')->after('mapping_type');
            $table->timestampTz('request_expires_at')->nullable()->after('expires_at');
            $table->timestampTz('rejected_at')->nullable()->after('request_expires_at');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX distributor_shop_mappings_live_pair_unique
                ON distributor_shop_mappings (shop_id, distributor_id)
                WHERE state IN ('pending_distributor', 'pending_shop', 'Active', 'Deactivated')
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS distributor_shop_mappings_live_pair_unique');
        Schema::table('distributor_shop_mappings', function (Blueprint $table): void {
            $table->dropColumn(['initiated_by', 'request_expires_at', 'rejected_at']);
        });
    }
};
