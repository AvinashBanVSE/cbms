'use client'

import { useState } from 'react'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { cbmsRequest } from '@/lib/cbms-api'
import OpaqueLink from '@/components/navigation/OpaqueLink'

type SetupStage = 'setup' | 'owner' | 'complete'

const InstallationSetup = () => {
  const [stage, setStage] = useState<SetupStage>('setup')
  const [setupCode, setSetupCode] = useState('')
  const [userCode, setUserCode] = useState('')
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('')

  async function submitSetup(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const values = Object.fromEntries(new FormData(event.currentTarget).entries())

    setBusy(true)
    setNotice('')

    try {
      await cbmsRequest('installation/setup', {
        legal_name: String(values.legal_name).trim(),
        professional_name: String(values.professional_name).trim()
      }, setupCode)
      setStage('owner')
    } catch (error) {
      setNotice(error instanceof Error ? error.message : 'Portal setup failed. Please retry.')
    } finally {
      setBusy(false)
    }
  }

  async function submitOwner(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const values = Object.fromEntries(new FormData(event.currentTarget).entries())

    setBusy(true)
    setNotice('')

    try {
      const response = await cbmsRequest('installation/owner', {
        name: String(values.name).trim(),
        email: String(values.email).trim(),
        password: String(values.password)
      }, setupCode)

      const result = (await response.json()) as { user_code?: string }

      setUserCode(result.user_code ?? '')
      setStage('complete')
    } catch (error) {
      setNotice(error instanceof Error ? error.message : 'Portal Owner creation failed. Please retry.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className='flex min-h-screen items-center justify-center px-4 py-10'>
      <Card className='w-full max-w-lg'>
        <CardHeader>
          <CardTitle>{stage === 'setup' ? 'Set up CBMS' : stage === 'owner' ? 'Create the Portal Owner' : 'CBMS setup complete'}</CardTitle>
          <CardDescription>
            {stage === 'setup' && 'Enter the portal identity and the deployment setup code provided by the authorized installer.'}
            {stage === 'owner' && 'Create the first Main Portal Owner account. Keep the setup code available until this step is complete.'}
            {stage === 'complete' && 'Save the generated User Code before signing in.'}
          </CardDescription>
        </CardHeader>
        <CardContent>
          {stage === 'setup' && (
            <form onSubmit={submitSetup}>
              <FieldGroup className='gap-4'>
                <Field><FieldLabel htmlFor='setupCode'>Deployment setup code*</FieldLabel><Input id='setupCode' value={setupCode} onChange={event => setSetupCode(event.target.value)} minLength={32} required /></Field>
                <Field><FieldLabel htmlFor='legalName'>Legal name*</FieldLabel><Input name='legal_name' id='legalName' maxLength={200} required /></Field>
                <Field><FieldLabel htmlFor='professionalName'>Professional name*</FieldLabel><Input name='professional_name' id='professionalName' maxLength={200} required /></Field>
                <Button type='submit' disabled={busy}>{busy ? 'Setting up…' : 'Continue'}</Button>
              </FieldGroup>
            </form>
          )}
          {stage === 'owner' && (
            <form onSubmit={submitOwner}>
              <FieldGroup className='gap-4'>
                <Field><FieldLabel htmlFor='ownerName'>Owner name*</FieldLabel><Input name='name' id='ownerName' maxLength={200} required /></Field>
                <Field><FieldLabel htmlFor='ownerEmail'>Email address*</FieldLabel><Input name='email' id='ownerEmail' type='email' maxLength={254} required /></Field>
                <Field><FieldLabel htmlFor='ownerPassword'>Permanent password*</FieldLabel><Input name='password' id='ownerPassword' type='password' required /></Field>
                <Button type='submit' disabled={busy}>{busy ? 'Creating owner…' : 'Create Portal Owner'}</Button>
              </FieldGroup>
            </form>
          )}
          {stage === 'complete' && <div className='space-y-4'><p>Your Portal Owner User Code is:</p><p className='bg-muted rounded-md p-4 text-center text-2xl font-semibold tracking-widest'>{userCode}</p><OpaqueLink href='/login' className='bg-primary text-primary-foreground inline-flex h-10 w-full items-center justify-center rounded-md px-4 text-sm font-medium'>Go to sign in</OpaqueLink></div>}
          {notice && <p role='alert' className='text-destructive mt-4 text-sm'>{notice}</p>}
        </CardContent>
      </Card>
    </div>
  )
}

export default InstallationSetup
