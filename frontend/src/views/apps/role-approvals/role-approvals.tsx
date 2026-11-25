'use client'

import { useCallback, useEffect, useState } from 'react'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'

/**
 * Live role-approval administration screen (plan step 8).
 *
 * Distinct from the demo `RolesApp` template, which renders hardcoded INITIAL_ROLES from a
 * zustand store and never touches the backend. This screen talks to the real governed API —
 * the entity-role approval queue — using the same CSRF-authenticated client pattern as
 * portal-setup, so decisions made here are actually evaluated server-side (D3).
 */

interface ApprovalRequest {
  id: string
  role_id: string
  role_name: string | null

  // Nullable marks an original assignment request (a beneficiary user is named); non-null marks
  // an EDIT of an assigned role, carrying its frozen proposed contents.
  user_id: string | null
  edit_payload: { name: string; actions: string[]; grant_actions: string[] } | null
  requester_id: string
  role_version: number
  stage: number
  stages: { required: number }[]
}

function shortId(id: string): string {
  return id.slice(0, 8)
}

/** Distinguish an assignment from a governed role edit, and show the frozen proposed contents. */
function describe(request: ApprovalRequest): { title: string; detail: string } {
  const role = request.role_name ? `“${request.role_name}”` : `role ${shortId(request.role_id)}`

  if (request.edit_payload) {
    const payload = request.edit_payload

    return {
      title: `Role edit · ${role}`,
      detail: `→ name ${payload.name}; actions ${payload.actions.join(', ') || 'none'}; grant ${payload.grant_actions.join(', ') || 'none'}`
    }
  }

  return {
    title: `Assignment · ${role}`,
    detail: request.user_id ? `for user ${shortId(request.user_id)}` : 'role contents'
  }
}

async function csrf(): Promise<string> {
  const response = await fetch('/api/v1/csrf', { credentials: 'include', cache: 'no-store' })

  if (!response.ok) throw new Error('Session initialization failed')

  return ((await response.json()) as { token: string }).token
}

async function loadRequests(businessId: string, token: string): Promise<ApprovalRequest[]> {
  const response = await fetch(`/api/v1/entities/${businessId}/roles/approvals`, {
    credentials: 'include',
    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token }
  })

  if (!response.ok) {
    if (response.status === 403) throw new Error('You do not manage this entity.')
    if (response.status === 404) throw new Error('Entity not found.')
    throw new Error('Could not load the approval queue.')
  }

  return ((await response.json()) as { data: ApprovalRequest[] }).data
}

async function decideRequest(
  businessId: string,
  change: string,
  decision: 'approve' | 'reject',
  token: string
): Promise<{ status: string; stage?: number }> {
  const response = await fetch(`/api/v1/entities/${businessId}/roles/approvals/${change}/decision`, {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
    body: JSON.stringify({ decision })
  })

  if (response.status === 403) throw new Error('You are not an eligible reviewer for this request.')
  if (response.status === 409) throw new Error('The request changed. Refresh and retry.')

  if (response.status === 422) {
    const body = (await response.json()) as { message?: string }

    throw new Error(body.message ?? 'Invalid decision.')
  }

  if (!response.ok) throw new Error('The decision could not be recorded.')

  return (await response.json()) as { status: string; stage?: number }
}

export function RoleApprovalsApp() {
  const [business, setBusiness] = useState('')
  const [requests, setRequests] = useState<ApprovalRequest[]>([])
  const [message, setMessage] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const refresh = useCallback(async (businessId: string) => {
    if (!businessId) return
    setLoading(true)
    setError(null)

    try {
      const token = await csrf()

      setRequests(await loadRequests(businessId, token))
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Something went wrong.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    void refresh(business)
  }, [business, refresh])

  const decide = async (change: string, decision: 'approve' | 'reject') => {
    setError(null)
    setMessage(null)

    try {
      const token = await csrf()
      const result = await decideRequest(business, change, decision, token)


      // The backend may accept a vote yet keep the request open for further required stages; an
      // 'approve' vote is not the same as the change being applied. Report the real status (D3).
      if (result.status === 'approved') setMessage('Approved and applied.')
      else if (result.status === 'pending') setMessage('Vote recorded. Awaiting further required approval.')
      else if (result.status === 'invalidated') setMessage('No longer applicable — the role or authority changed.')
      else if (result.status === 'rejected') setMessage('Rejected.')
      else if (result.status === 'expired') setMessage('This request expired.')
      else setMessage(`Decided: ${result.status}.`)
      await refresh(business)
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Something went wrong.')
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Role Change Approvals</CardTitle>
        <CardDescription>
          Review governed role edits and assignments for an entity. Decisions here are enforced server-side; a
          multi-stage request needs its full reviewer count before it applies.
        </CardDescription>
      </CardHeader>
      <CardContent className='space-y-4'>
        <div className='flex items-end gap-2'>
          <div className='flex-1'>
            <Input
              placeholder='Entity (business) id'
              value={business}
              onChange={event => setBusiness(event.target.value)}
            />
          </div>
          <Button variant='outline' disabled={!business || loading} onClick={() => void refresh(business)}>
            Refresh
          </Button>
        </div>
        {error && <p className='text-destructive text-sm'>{error}</p>}
        {message && <p className='text-muted-foreground text-sm'>{message}</p>}
        {requests.length === 0 ? (
          <p className='text-muted-foreground text-sm'>
            {business
              ? loading
                ? 'Loading…'
                : 'No pending approvals.'
              : 'Enter an entity id to load its approval queue.'}
          </p>
        ) : (
          <ul className='divide-y'>
            {requests.map(request => {
              const { title, detail } = describe(request)
              const totalStages = request.stages.length

              return (
                <li key={request.id} className='flex flex-wrap items-center justify-between gap-2 py-2'>
                  <div className='text-sm'>
                    <p className='font-medium'>{title}</p>
                    <p className='text-muted-foreground'>
                      {detail} · requester {shortId(request.requester_id)} · version {request.role_version}
                      {totalStages > 1 ? ` · stage ${request.stage + 1}/${totalStages}` : ''}
                    </p>
                  </div>
                  <div className='flex gap-2'>
                    <Button size='sm' onClick={() => void decide(request.id, 'approve')}>
                      Approve
                    </Button>
                    <Button size='sm' variant='destructive' onClick={() => void decide(request.id, 'reject')}>
                      Reject
                    </Button>
                  </div>
                </li>
              )
            })}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}
