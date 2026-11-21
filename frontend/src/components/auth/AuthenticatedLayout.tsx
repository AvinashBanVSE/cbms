'use client'

import type { ReactNode } from 'react'
import { useEffect, useState } from 'react'

import { useRouter } from 'next/navigation'
import { Loader2Icon } from 'lucide-react'

import { CbmsApiError, cbmsRequest } from '@/lib/cbms-api'

const AuthenticatedLayout = ({ children }: Readonly<{ children: ReactNode }>) => {
  const router = useRouter()
  const [authorized, setAuthorized] = useState(false)

  useEffect(() => {
    let active = true

    cbmsRequest('workspace-access')
      .then(() => {
        if (active) setAuthorized(true)
      })
      .catch((error: unknown) => {
        if (!active) return

        if (error instanceof CbmsApiError && error.status === 401) {
          router.replace('/login')

          return
        }

        router.replace('/error')
      })

    return () => {
      active = false
    }
  }, [router])

  if (!authorized) {
    return (
      <div className='flex min-h-screen items-center justify-center' role='status' aria-label='Checking sign-in status'>
        <Loader2Icon className='text-primary size-6 animate-spin' />
      </div>
    )
  }

  return children
}

export default AuthenticatedLayout
