import { cookies, headers } from 'next/headers'
import { NextResponse } from 'next/server'

import { addNavigationEntry, isPageKey, navigationCookie, navigationLifetimeSeconds, openNavigationMap, sealNavigationMap } from '@/lib/opaque-navigation'

export function GET() {
  return new Response(null, { status: 204, headers: { 'Cache-Control': 'no-store' } })
}

export async function POST(request: Request) {
  const requestHeaders = await headers()
  const fetchSite = requestHeaders.get('sec-fetch-site')

  if (fetchSite && fetchSite !== 'same-origin') return NextResponse.json({ message: 'Invalid request origin.' }, { status: 403 })

  const payload = (await request.json().catch(() => null)) as { page?: unknown } | null

  if (!isPageKey(payload?.page)) return NextResponse.json({ message: 'Unknown page.' }, { status: 422 })

  const store = await cookies()
  const current = await openNavigationMap(store.get(navigationCookie)?.value)
  const issued = addNavigationEntry(current, payload.page)
  const response = NextResponse.json({ opaqueId: issued.id }, { headers: { 'Cache-Control': 'no-store' } })

  response.cookies.set(navigationCookie, await sealNavigationMap(issued.map), {
    httpOnly: true,
    sameSite: 'strict',
    secure: process.env.NODE_ENV === 'production',
    path: '/',
    maxAge: navigationLifetimeSeconds
  })

  return response
}
