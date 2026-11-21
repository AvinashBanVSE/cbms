import { type NextRequest, NextResponse } from 'next/server'

import { addNavigationEntry, navigationCookie, navigationLifetimeSeconds, openNavigationMap, sealNavigationMap, type PageKey } from '@/lib/opaque-navigation'

const readablePages: Record<string, PageKey> = {
  '/': 'login',
  '/login': 'login',
  '/setup': 'setup',
  '/error': 'error',
  '/dashboard': 'dashboard',
  '/dashboard/orders': 'dashboard-orders',
  '/user-profile': 'user-profile',
  '/user-settings': 'user-settings',
  '/apps/calendar': 'calendar',
  '/apps/mail': 'mail',
  '/apps/users/list': 'users-list',
  '/apps/users/view': 'users-view',
  '/datatable': 'datatable',
  '/forms/form-layouts/horizontal': 'form-horizontal',
  '/forms/form-layouts/vertical': 'form-vertical',
  '/forms/form-validation': 'form-validation'
}

export async function proxy(request: NextRequest) {
  const page = readablePages[request.nextUrl.pathname]

  if (!page) return NextResponse.next()

  const current = await openNavigationMap(request.cookies.get(navigationCookie)?.value)
  const issued = addNavigationEntry(current, page)
  const response = NextResponse.redirect(new URL(`/${issued.id}`, request.url))

  response.cookies.set(navigationCookie, await sealNavigationMap(issued.map), {
    httpOnly: true,
    sameSite: 'strict',
    secure: process.env.NODE_ENV === 'production',
    path: '/',
    maxAge: navigationLifetimeSeconds
  })

  return response
}

export const config = {
  matcher: ['/', '/login', '/setup', '/error', '/dashboard/:path*', '/user-profile', '/user-settings', '/apps/:path*', '/datatable', '/forms/:path*']
}
