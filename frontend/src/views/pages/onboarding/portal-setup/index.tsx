'use client'

import { useEffect, useState, type FormEvent } from 'react'
import Link from 'next/link'
import { ChevronLeftIcon, CheckCircle2Icon } from 'lucide-react'

import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { cn } from '@/lib/utils'

type Stage = 'setup_required' | 'owner_required' | 'ready'
type Step = 'portal' | 'owner' | 'complete'

const steps: { id: Step; title: string; description: string }[] = [
  { id: 'portal', title: 'Portal Setup', description: 'Enter the legal and professional names of your Main Portal.' },
  { id: 'owner', title: 'Owner Setup', description: 'Create the first Main Portal Owner account.' },
  { id: 'complete', title: 'Setup Complete', description: 'Your Main Portal is ready. Keep your assigned user code for sign-in.' }
]

function errorMessage(status: number, detail?: string): string {
  if (status === 403) return 'The deployment secret is invalid or setup is not authorized.'
  if (status === 409) return 'The setup stage has changed. Refresh to continue from the current stage.'
  if (status === 422) return detail || 'Please check the entered information.'
  if (status === 429) return 'Too many attempts. Please wait before trying again.'
  return detail || 'The request could not be completed. Please try again.'
}

async function apiRequest(path: string, secret?: string, body?: Record<string, string>) {
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (body) {
    const csrfResponse = await fetch('/api/v1/csrf', { credentials: 'include', cache: 'no-store' })
    if (!csrfResponse.ok) throw new Error('Unable to initialize the secure session.')
    const csrf = (await csrfResponse.json()) as { token: string }
    headers['Content-Type'] = 'application/json'
    headers['X-CSRF-TOKEN'] = csrf.token
    if (secret) headers['X-Setup-Code'] = secret
  }
  const response = await fetch('/api/v1/' + path, {
    method: body ? 'POST' : 'GET',
    credentials: 'include',
    cache: 'no-store',
    headers,
    ...(body ? { body: JSON.stringify(body) } : {})
  })
  const result = (await response.json().catch(() => ({}))) as {
    stage?: Stage
    user_code?: string
    message?: string
    errors?: Record<string, string[]>
  }
  if (!response.ok) {
    const firstError = result.errors && Object.values(result.errors).flat()[0]
    throw new Error(errorMessage(response.status, firstError || result.message))
  }
  return result
}

export default function PortalSetup() {
  const [step, setStep] = useState<Step>('portal')
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [legalName, setLegalName] = useState('')
  const [professionalName, setProfessionalName] = useState('')
  const [ownerName, setOwnerName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [secret, setSecret] = useState('')
  const [userCode, setUserCode] = useState('')

  useEffect(() => {
    let active = true
    apiRequest('installation')
      .then(result => {
        if (!active) return
        if (result.stage === 'owner_required') setStep('owner')
        else if (result.stage === 'ready') setStep('complete')
        else if (result.stage !== 'setup_required') setError('Unexpected installation stage.')
      })
      .catch(err => { if (active) setError(err instanceof Error ? err.message : 'Unable to load setup status.') })
      .finally(() => { if (active) setLoading(false) })
    return () => { active = false }
  }, [])

  const index = steps.findIndex(item => item.id === step)
  const current = steps[index]

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (submitting) return
    setError('')
    setSubmitting(true)
    try {
      if (step === 'portal') {
        await apiRequest('installation/setup', secret, {
          legal_name: legalName.trim(),
          professional_name: professionalName.trim()
        })
        setStep('owner')
      } else if (step === 'owner') {
        const result = await apiRequest('installation/owner', secret, {
          name: ownerName.trim(), email: email.trim(), password
        })
        if (!result.user_code) throw new Error('Setup succeeded but no user code was returned. Contact the administrator.')
        setUserCode(result.user_code)
        setPassword('')
        setSecret('')
        setStep('complete')
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Setup failed.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Card className='bg-muted flex w-full flex-col-reverse overflow-hidden py-0 lg:min-h-145 lg:flex-row'>
      <CardContent className='flex w-full flex-col justify-start p-6 lg:w-[42%] lg:p-10'>
        <div className='min-h-5'>
          {step === 'owner' && (
            <span className='text-muted-foreground inline-flex items-center gap-1 text-sm'>
              <ChevronLeftIcon className='size-4' /> Portal setup saved
            </span>
          )}
        </div>
        <div className='flex flex-1 flex-col items-start justify-between gap-10'>
          <div className='flex flex-col gap-3'>
            <h1 className='text-xl leading-tight font-medium tracking-tight md:text-4xl'>{current.title}</h1>
            <p className='text-muted-foreground max-w-xs text-base leading-relaxed'>{current.description}</p>
          </div>
          <div className='flex w-full flex-col gap-5'>
            {step === 'complete' ? (
              <Button className='w-full' render={<Link href='/pages/auth/login-v3' />} nativeButton={false}>
                Continue to Sign In
              </Button>
            ) : (
              <Button className='w-full' type='submit' form='portal-setup-form' disabled={loading || submitting}>
                {submitting ? 'Saving...' : step === 'portal' ? 'Continue to Owner Setup' : 'Create Portal Owner'}
              </Button>
            )}
            <div className='flex items-center justify-center gap-1.5' aria-label={'Step ' + (index + 1) + ' of 3'}>
              {steps.map((item, i) => (
                <span key={item.id} className={cn('h-2 rounded-full transition-all duration-300',
                  i === index ? 'bg-primary w-5' : 'bg-muted-foreground/30 w-2')} />
              ))}
            </div>
          </div>
        </div>
      </CardContent>
      <div className='bg-background flex flex-1 items-start justify-center p-4 transition-colors duration-500 sm:p-6 lg:p-10'>
        <div className='w-full'>
          {error && <p role='alert' className='text-destructive mb-4 text-sm'>{error}</p>}
          {loading ? <p className='text-muted-foreground'>Checking installation status...</p> : step === 'complete' ? (
            <div className='flex flex-col gap-5'>
              <CheckCircle2Icon className='text-primary size-12' />
              <h2 className='text-2xl font-semibold'>Main Portal setup completed</h2>
              {userCode ? (
                <div className='space-y-2'>
                  <Label>Your assigned User Code</Label>
                  <p className='bg-muted rounded-md p-4 font-mono text-2xl font-semibold tracking-wider'>{userCode}</p>
                  <p className='text-muted-foreground text-sm'>Save this code securely. You will need it with your email and password to sign in.</p>
                </div>
              ) : (
                <p className='text-muted-foreground text-sm'>Setup is already complete. Use the User Code assigned during owner creation to sign in.</p>
              )}
            </div>
          ) : (
            <form id='portal-setup-form' onSubmit={submit} className='flex flex-col gap-5'>
              {step === 'portal' ? (
                <>
                  <div className='space-y-2'><Label htmlFor='legal-name'>Legal Name</Label>
                    <Input id='legal-name' required maxLength={200} value={legalName} onChange={e => setLegalName(e.target.value)} autoComplete='organization' /></div>
                  <div className='space-y-2'><Label htmlFor='professional-name'>Professional Name</Label>
                    <Input id='professional-name' required maxLength={200} value={professionalName} onChange={e => setProfessionalName(e.target.value)} /></div>
                </>
              ) : (
                <>
                  <div className='space-y-2'><Label htmlFor='owner-name'>Owner Name</Label>
                    <Input id='owner-name' required maxLength={200} value={ownerName} onChange={e => setOwnerName(e.target.value)} autoComplete='name' /></div>
                  <div className='space-y-2'><Label htmlFor='owner-email'>Email</Label>
                    <Input id='owner-email' type='email' required maxLength={254} value={email} onChange={e => setEmail(e.target.value)} autoComplete='email' /></div>
                  <div className='space-y-2'><Label htmlFor='owner-password'>Permanent Password</Label>
                    <Input id='owner-password' type='password' required minLength={15} maxLength={72} value={password} onChange={e => setPassword(e.target.value)} autoComplete='new-password' />
                    <p className='text-muted-foreground text-xs'>At least 15 characters; common or compromised passwords are not accepted.</p></div>
                </>
              )}
              <div className='space-y-2'><Label htmlFor='deployment-secret'>Deployment Secret</Label>
                <Input id='deployment-secret' type='password' required minLength={32} value={secret} onChange={e => setSecret(e.target.value)} autoComplete='off' />
                <p className='text-muted-foreground text-xs'>Enter the secret provided by your deployment administrator.</p></div>
            </form>
          )}
        </div>
      </div>
    </Card>
  )
}
