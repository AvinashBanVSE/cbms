<?php

declare(strict_types=1);

namespace App\Configuration;

use Illuminate\Support\Facades\DB;

final class OrganizationCredentialPolicy
{
    public const MODES = ['temporary_password', 'otp', 'both'];

    /** @return array{mode: ?string, source: ?string} */
    public function resolve(string $tenant): array
    {
        $subject = DB::table('customer_tenants as tenant')
            ->leftJoin('organization_credential_policies as organization', 'organization.tenant_id', '=', 'tenant.id')
            ->leftJoin('provider_credential_defaults as provider', 'provider.provider_id', '=', 'tenant.provider_id')
            ->leftJoin('portal_installation as installation', 'installation.id', '=', DB::raw('1'))
            ->leftJoin('provider_credential_defaults as portal', 'portal.provider_id', '=', 'installation.portal_id')
            ->where('tenant.id', $tenant)->first([
                'tenant.provider_id', 'installation.portal_id', 'organization.mode as organization_mode',
                'provider.mode as provider_mode', 'portal.mode as portal_mode',
            ]);

        abort_if($subject === null, 404);

        foreach ([
            'organization' => $subject->organization_mode,
            'sub_portal' => $subject->provider_id !== $subject->portal_id ? $subject->provider_mode : null,
            'main_portal' => $subject->portal_mode,
        ] as $source => $mode) {
            if (in_array($mode, self::MODES, true)) {
                return ['mode' => $mode, 'source' => $source];
            }
        }

        return ['mode' => null, 'source' => null];
    }
}
