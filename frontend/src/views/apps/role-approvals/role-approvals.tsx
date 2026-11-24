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
  user_id: string | null
  op: string
  reason: string
  requester_id: string
  created_at: string
}

async function csrf(): Promise<string> {
  const response = await fetch('/api/v1/csrf', { credentials: 'include', cache: 'no-store' })
  if (!response.ok) throw new Error('Session initialization failed')
  return (await response.json() as { token: string }).token
}

async function loadRequests(businessId: string, token: string): Promise<ApprovalRequest[]> {
  const response = await fetch(`/api/v1/entities/${businessId}/roles/approvals`, {
    credentials: 'include', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token }
  })
  if (!response.ok) {
    if (response.status === 403) throw new Error('You do not manage this entity.')
    if (response.status === 404) throw new Error('Entity not found.')
    throw new Error('Could not load the approval queue.')
  }
  return (await response.json() as { data: ApprovalRequest[] }).data
}

async function decideRequest(businessId: string, change: string, decision: 'approve' | 'reject', token: string): Promise<void> {
  const response = await fetch(`/api/v1/entities/${businessId}/roles/approvals/${change}/decision`, {
    method: 'POST', credentials: 'include',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
    body: JSON.stringify({ decision })
  })
  if (response.status === 409) throw new Error('The request changed. Refresh and retry.')
  if (response.status === 422) {
    const body = await response.json() as { message?: string }
    throw new Error(body.message ?? 'Invalid decision.')
  }
  if (!response.ok) throw new Error('The decision could not be recorded.')
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

  useEffect(() => { void refresh(business) }, [business, refresh])

  const decide = async (change: string, decision: 'approve' | 'reject') => {
    setError(null)
    setMessage(null)
    try {
      const token = await csrf()
      await decideRequest(business, change, decision, token)
      setMessage(decision === 'approve' ? 'Approved and applied.' : 'Rejected.')
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
          Review governed role edits and assignments for an entity. Decisions here are enforced server-side.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="flex gap-2 items-end">
          <div className="flex-1">
            <Input placeholder="Entity (business) id" value={business} onChange={event => setBusiness(event.target.value)} />
          </div>
          <Button variant="outline" disabled={!business || loading} onClick={() => void refresh(business)}>
            Refresh
          </Button>
        </div>
        {error && <p className="text-sm text-destructive">{error}</p>}
        {message && <p className="text-sm text-muted-foreground">{message}</p>}
        {requests.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            {business ? (loading ? 'Loading…' : 'No pending approvals.') : 'Enter an entity id to load its approval queue.'}
          </p>
        ) : (
          <ul className="divide-y">
            {requests.map(request => (
              <li key={request.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                <div className="text-sm">
                  <p className="font-medium">{request.op} · role {request.role_id}</p>
                  <p className="text-muted-foreground">
                    {request.user_id ? `user ${request.user_id}` : 'role contents'} · reason: {request.reason}
                  </p>
                </div>
                <div className="flex gap-2">
                  <Button size="sm" onClick={() => void decide(request.id, 'approve')}>Approve</Button>
                  <Button size="sm" variant="destructive" onClick={() => void decide(request.id, 'reject')}>Reject</Button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}
