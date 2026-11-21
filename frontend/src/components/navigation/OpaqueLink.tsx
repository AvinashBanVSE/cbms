'use client'

import { forwardRef, type AnchorHTMLAttributes, type MouseEvent } from 'react'

import { useRouter } from 'next/navigation'

import { navigateToPage } from '@/lib/client-navigation'
import type { PageKey } from '@/lib/opaque-navigation'

const pathPages: Record<string, PageKey> = {
  '/': 'login', '/login': 'login', '/setup': 'setup', '/error': 'error', '/dashboard': 'dashboard',
  '/dashboard/orders': 'dashboard-orders', '/user-profile': 'user-profile', '/user-settings': 'user-settings',
  '/apps/calendar': 'calendar', '/apps/mail': 'mail', '/apps/users/list': 'users-list', '/apps/users/view': 'users-view',
  '/datatable': 'datatable', '/forms/form-layouts/horizontal': 'form-horizontal',
  '/forms/form-layouts/vertical': 'form-vertical', '/forms/form-validation': 'form-validation'
}

type Props = Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & { href: string }

const OpaqueLink = forwardRef<HTMLAnchorElement, Props>(({ href, onClick, target, ...props }, ref) => {
  const router = useRouter()
  const page = pathPages[href]

  async function handleClick(event: MouseEvent<HTMLAnchorElement>) {
    onClick?.(event)
    if (event.defaultPrevented || !page || target === '_blank') return
    event.preventDefault()
    await navigateToPage(router, page)
  }

  return <a ref={ref} href={page ? '#' : href} target={target} onClick={handleClick} {...props} />
})

OpaqueLink.displayName = 'OpaqueLink'

export default OpaqueLink
