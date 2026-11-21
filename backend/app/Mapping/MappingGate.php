<?php

declare(strict_types=1);

namespace App\Mapping;

use Illuminate\Support\Facades\DB;

/**
 * Mapping-type operation gate (§12.6).
 *
 * An Active Distributor–Shop mapping permits only the transaction verbs and data fields
 * expressly authorized for its mapping type by Main Portal governance. Authorization is
 * deny-by-default: an empty allowlist grants no transaction and no field, and a mapping
 * never confers access to anything it does not list. Nothing here reads or returns business
 * data — it resolves whether a mapped operation is authorized at all. The allowlist itself is
 * data/configuration driven (§14.2), so new permissions are published by governance rather
 * than by editing this class.
 */
final class MappingGate
{
    /**
     * Assert a mapped operation is authorized for the mapping type, transaction and fields.
     *
     * @param  list<string>  $fields
     */
    public function authorize(string $mappingId, string $transaction, array $fields = []): void
    {
        $type = $this->type($mappingId);

        $permitted = $type['allowed_transactions'][$transaction] ?? null;
        abort_if($permitted === null, 403,
            'The transaction is not authorized for this mapping type.');
        foreach ($fields as $field) {
            abort_if(! in_array($field, $permitted['fields'] ?? [], true), 403,
                'A requested data field is not authorized for this transaction.');
        }
    }

    /**
     * Assert the data fields requested through a mapping are shared. The fields a mapping may
     * disclose are exactly those listed for the mapping type — nothing more (§12.6: only
     * information necessary for the expressly authorized relationship interactions).
     *
     * @param  list<string>  $fields
     */
    public function authorizeFields(string $mappingId, array $fields): void
    {
        $type = $this->type($mappingId);

        $shared = $type['shared_data_fields'] ?? [];
        foreach ($fields as $field) {
            abort_if(! in_array($field, $shared, true), 403,
                'A requested data field is not shared through this mapping type.');
        }
    }

    /**
     * The authorized transaction catalogue for a mapping, as either party may view it.
     * Deny-by-default: an empty allowlist presents no verbs and no shared fields.
     *
     * @return array{allowed_transactions: array<string, array<string, list<string>>>, shared_data_fields: list<string>}
     */
    public function catalogue(string $mappingId): array
    {
        $type = $this->type($mappingId);

        return ['allowed_transactions' => $type['allowed_transactions'] ?? [],
            'shared_data_fields' => $type['shared_data_fields'] ?? []];
    }

    /** @return array{allowed_transactions?: array<string, array<string, list<string>>>, shared_data_fields?: list<string>} */
    private function type(string $mappingId): array
    {
        $mapping = DB::table('distributor_shop_mappings')->where('id', $mappingId)->first();
        abort_if($mapping === null, 404);

        $type = config('mapping_types')[$mapping->mapping_type] ?? null;
        abort_if($type === null, 422, 'The mapping type is not recognized.');

        /** @var array{allowed_transactions?: array<string, array<string, list<string>>>, shared_data_fields?: list<string>} $type */
        return $type;
    }
}
