import { Building2Icon, KeyRoundIcon, ShieldCheckIcon } from 'lucide-react'

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'

const foundationAreas = [
  {
    title: 'Secure access',
    description: 'Sign-in, credential recovery and authenticator workflows are governed by the Portal API.',
    icon: KeyRoundIcon
  },
  {
    title: 'Business structure',
    description: 'Organization, Distributor and Shop relationships retain their independent authority boundaries.',
    icon: Building2Icon
  },
  {
    title: 'Controlled operations',
    description: 'Permissions, scope, subscription entitlement and approval are evaluated as separate controls.',
    icon: ShieldCheckIcon
  }
]

const SystemOverviewPage = () => {
  return (
    <section className='space-y-6'>
      <div className='space-y-2'>
        <p className='text-primary text-sm font-medium'>CBMS</p>
        <h1 className='text-3xl font-semibold tracking-tight'>System overview</h1>
        <p className='text-muted-foreground max-w-2xl'>
          A secure foundation for governed business operations across the CBMS hierarchy.
        </p>
      </div>

      <div className='grid gap-4 md:grid-cols-3'>
        {foundationAreas.map(({ title, description, icon: Icon }) => (
          <Card key={title}>
            <CardHeader className='space-y-3'>
              <div className='bg-primary/10 text-primary flex size-10 items-center justify-center rounded-lg'>
                <Icon className='size-5' />
              </div>
              <div>
                <CardTitle>{title}</CardTitle>
                <CardDescription className='mt-2'>{description}</CardDescription>
              </div>
            </CardHeader>
          </Card>
        ))}
      </div>

      <Card>
        <CardHeader>
          <CardTitle>System data</CardTitle>
          <CardDescription>
            Authorized system data will appear here after the API session is connected.
          </CardDescription>
        </CardHeader>
        <CardContent className='text-muted-foreground text-sm'>
          This page deliberately does not display template orders, sample payments or demo business records.
        </CardContent>
      </Card>
    </section>
  )
}

export default SystemOverviewPage
