'use client'

import { useEffect, useState } from 'react'

import { useRouter } from 'next/navigation'
import { Building2Icon, KeyRoundIcon, Loader2Icon, ShieldCheckIcon } from 'lucide-react'

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { CbmsApiError, cbmsRequest } from '@/lib/cbms-api'
import { navigateToPage } from '@/lib/client-navigation'

const foundationAreas = [
  { title: 'Secure access', description: 'Sign-in, credential recovery and authenticator workflows are governed by the Portal API.', icon: KeyRoundIcon },
  { title: 'Business structure', description: 'Organization, Distributor and Shop relationships retain their independent authority boundaries.', icon: Building2Icon },
  { title: 'Controlled operations', description: 'Permissions, scope, subscription entitlement and approval are evaluated as separate controls.', icon: ShieldCheckIcon }
]

type Installation = { stage: 'setup_required' | 'owner_required' | 'ready' }
type WorkspaceAccess = { administration?: string[]; mapping_contexts?: unknown[]; delegations?: unknown[] }

const SystemOverview = () => {
  const router = useRouter()
  const [installation, setInstallation] = useState<Installation | null>(null)
  const [workspace, setWorkspace] = useState<WorkspaceAccess | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  useEffect(() => {
    let active = true

    async function load() {
      try {
        const installationResponse = await cbmsRequest('installation')
        const installationData = (await installationResponse.json()) as Installation

        if (!active) return
        setInstallation(installationData)

        if (installationData.stage === 'ready') {
          const workspaceResponse = await cbmsRequest('workspace-access')

          if (active) setWorkspace((await workspaceResponse.json()) as WorkspaceAccess)
        }
      } catch (error) {
        if (!active) return

        if (error instanceof CbmsApiError && error.status === 401) {
          void navigateToPage(router, 'login', true)

          return
        }

        setNotice(error instanceof Error ? error.message : 'Workspace unavailable. Please retry.')
      }
    }

    void load()

    return () => { active = false }
  }, [router])

  return (
    <section className='space-y-6'>
      <div className='space-y-2'>
        <p className='text-primary text-sm font-medium'>CBMS</p>
        <h1 className='text-3xl font-semibold tracking-tight'>System overview</h1>
        <p className='text-muted-foreground max-w-2xl'>A secure foundation for governed business operations across the CBMS hierarchy.</p>
      </div>
      <div className='grid gap-4 md:grid-cols-3'>
        {foundationAreas.map(({ title, description, icon: Icon }) => (
          <Card key={title}><CardHeader className='space-y-3'><div className='bg-primary/10 text-primary flex size-10 items-center justify-center rounded-lg'><Icon className='size-5' /></div><div><CardTitle>{title}</CardTitle><CardDescription className='mt-2'>{description}</CardDescription></div></CardHeader></Card>
        ))}
      </div>
      <Card>
        <CardHeader><CardTitle>System data</CardTitle><CardDescription>Authorized system data will appear here after the API session is connected.</CardDescription></CardHeader>
        <CardContent className='text-muted-foreground space-y-3 text-sm'>
          {notice && <p className='text-destructive'>{notice}</p>}
          {!installation && !notice && <p className='flex items-center gap-2'><Loader2Icon className='size-4 animate-spin' />Loading installation status…</p>}
          {installation && <p>Installation stage: <span className='text-foreground font-medium'>{installation.stage}</span></p>}
          {installation?.stage === 'ready' && workspace && <p>Authorized administration areas: <span className='text-foreground font-medium'>{workspace.administration?.length ?? 0}</span>; mapping contexts: <span className='text-foreground font-medium'>{workspace.mapping_contexts?.length ?? 0}</span>.</p>}
          {installation && installation.stage !== 'ready' && <p>Complete installation setup and owner creation before using authenticated workspaces.</p>}
          {!notice && installation?.stage === 'ready' && !workspace && <p>Loading authorized workspace access…</p>}
        </CardContent>
      </Card>
    </section>
  )
}

export default SystemOverview
