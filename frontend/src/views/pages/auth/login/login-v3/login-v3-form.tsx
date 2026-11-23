'use client'

import { useState, type FormEvent } from 'react'
import { EyeIcon, EyeOffIcon } from 'lucide-react'

import { Button } from '@/components/ui/button'
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group'

type LoginStage = 'credentials' | 'authenticator_required' | 'authenticator_enrollment_required' | 'authenticated'

const LoginV3Form = () => {
  const [isVisible, setIsVisible] = useState(false)
  const [code, setCode] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [authenticatorCode, setAuthenticatorCode] = useState('')
  const [stage, setStage] = useState<LoginStage>('credentials')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (busy) return
    setBusy(true)
    setError('')
    try {
      const csrfResponse = await fetch('/api/v1/csrf', { credentials: 'include', cache: 'no-store' })
      if (!csrfResponse.ok) throw new Error('Unable to initialize a secure login session.')
      const csrf = (await csrfResponse.json()) as { token: string }
      const response = await fetch('/api/v1/login', {
        method: 'POST',
        credentials: 'include',
        cache: 'no-store',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrf.token
        },
        body: JSON.stringify({
          code: code.trim().toUpperCase(),
          email: email.trim(),
          password,
          ...(stage === 'authenticator_required' ? { authenticator_code: authenticatorCode } : {})
        })
      })
      if (response.status === 204) {
        setPassword('')
        setAuthenticatorCode('')
        setStage('authenticated')
        return
      }
      if (response.status === 202) {
        const data = (await response.json()) as { stage?: string }
        if (data.stage === 'authenticator_required') {
          setStage('authenticator_required')
          return
        }
        if (data.stage === 'authenticator_enrollment_required') {
          setPassword('')
          setStage('authenticator_enrollment_required')
          return
        }
        throw new Error('Unexpected authentication response.')
      }
      if (response.status === 401) throw new Error('Invalid login credentials or authenticator code.')
      if (response.status === 429) throw new Error('Too many attempts. Please try again later.')
      if (response.status === 422) throw new Error('Please verify your User Code, email and password.')
      throw new Error('Unable to sign in. Please try again.')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to sign in.')
    } finally {
      setBusy(false)
    }
  }

  if (stage === 'authenticated') {
    return <p role='status' className='text-sm'>Sign-in successful. Your session is now authenticated.</p>
  }

  if (stage === 'authenticator_enrollment_required') {
    return (
      <p role='status' className='text-sm'>
        Your organization requires authenticator enrollment before sign-in. Enrollment must be completed through the approved authenticator setup flow.
      </p>
    )
  }

  return (
    <form onSubmit={submit}>
      <FieldGroup className='gap-4'>
        {error && <p role='alert' className='text-destructive text-sm'>{error}</p>}
        <Field className='gap-2'>
          <FieldLabel htmlFor='userCode'>User Code*</FieldLabel>
          <Input
            id='userCode'
            value={code}
            onChange={event => setCode(event.target.value.toUpperCase())}
            required
            maxLength={6}
            pattern='[A-Z]{2}[0-9]{4}'
            placeholder='AA0000'
            autoComplete='username'
            disabled={busy}
          />
        </Field>
        <Field className='gap-2'>
          <FieldLabel htmlFor='userEmail'>Email address*</FieldLabel>
          <Input id='userEmail' type='email' value={email} onChange={event => setEmail(event.target.value)}
            placeholder='Enter your email address' autoComplete='email' required disabled={busy} />
        </Field>
        <Field className='w-full gap-2'>
          <FieldLabel htmlFor='password'>Password*</FieldLabel>
          <InputGroup>
            <InputGroupInput id='password' type={isVisible ? 'text' : 'password'}
              value={password} onChange={event => setPassword(event.target.value)}
              autoComplete='current-password' required disabled={busy} placeholder='Enter your password' />
            <InputGroupAddon align='inline-end' className='pr-1.5'>
              <Button type='button' variant='ghost' size='icon' onClick={() => setIsVisible(value => !value)}
                className='text-muted-foreground rounded-l-none hover:bg-transparent'
                aria-label={isVisible ? 'Hide password' : 'Show password'}>
                {isVisible ? <EyeOffIcon /> : <EyeIcon />}
              </Button>
            </InputGroupAddon>
          </InputGroup>
        </Field>
        {stage === 'authenticator_required' && (
          <Field className='gap-2'>
            <FieldLabel htmlFor='authenticatorCode'>Authenticator Code*</FieldLabel>
            <Input id='authenticatorCode' value={authenticatorCode}
              onChange={event => setAuthenticatorCode(event.target.value.replace(/\D/g, '').slice(0, 6))}
              inputMode='numeric' pattern='[0-9]{6}' maxLength={6} required autoComplete='one-time-code'
              placeholder='6-digit code' disabled={busy} />
          </Field>
        )}
        <Field>
          <Button className='w-full' type='submit' disabled={busy}>
            {busy ? 'Signing in...' : stage === 'authenticator_required' ? 'Verify and Sign In' : 'Sign In to CBM Portal'}
          </Button>
        </Field>
      </FieldGroup>
    </form>
  )
}

export default LoginV3Form
