'use client'

import type { PageKey } from '@/lib/opaque-navigation'

type Router = { push(href: string): void; replace(href: string): void }

export async function issueOpaqueId(page: PageKey): Promise<string> {
  const response = await fetch('/navigation', {
    method: 'POST',
    credentials: 'same-origin',
    cache: 'no-store',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ page })
  })

  if (!response.ok) throw new Error('Navigation is unavailable. Please retry.')
  const payload = (await response.json()) as { opaqueId?: unknown }

  if (typeof payload.opaqueId !== 'string') throw new Error('Navigation is unavailable. Please retry.')

  return payload.opaqueId
}

export async function navigateToPage(router: Router, page: PageKey, replace = false): Promise<void> {
  const id = await issueOpaqueId(page)

  if (replace) router.replace(`/${id}`)
  else router.push(`/${id}`)
}

export async function assignPage(page: PageKey): Promise<void> {
  const id = await issueOpaqueId(page)

  window.location.assign(`/${id}`)
}
