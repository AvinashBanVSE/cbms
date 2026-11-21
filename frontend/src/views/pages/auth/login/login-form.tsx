'use client'

// Rect Import
import { useState } from 'react'

// Next Imports
import Link from 'next/link'

// Third-party Imports
import { EyeIcon, EyeOffIcon } from 'lucide-react'

// Components Import
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group'
import { cbmsRequest } from '@/lib/cbms-api'

const LoginForm = () => {
  const [isVisible, setIsVisible] = useState(false)
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (busy) return
    const values = Object.fromEntries(new FormData(event.currentTarget).entries())

    setBusy(true)
    setNotice('')

    try {
      const response = await cbmsRequest('login', {
        code: String(values.code).trim().toUpperCase(),
        email: String(values.email).trim(),
        password: String(values.password)
      })

      setNotice(response.status === 202 ? 'Additional authenticator verification is required.' : 'Signed in successfully.')
      if (response.status === 200) window.location.assign('/dashboard')
    } catch (error) {
      setNotice(error instanceof Error ? error.message : 'Sign-in failed. Please retry.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={submit}>
      <FieldGroup className='gap-4'>
        {/* User Code */}
        <Field className='gap-2'>
          <FieldLabel htmlFor='userCode' className='leading-5'>
            User Code*
          </FieldLabel>
          <Input name='code' id='userCode' inputMode='text' autoCapitalize='characters' maxLength={6} placeholder='AA0000' required />
        </Field>
        <Field className='gap-2'>
          <FieldLabel htmlFor='email' className='leading-5'>
            Email address*
          </FieldLabel>
          <Input name='email' type='email' id='email' placeholder='name@example.com' required />
        </Field>
        {/* Password */}
        <Field className='w-full gap-2'>
          <FieldLabel htmlFor='password' className='leading-5'>
            Password*
          </FieldLabel>
          <InputGroup>
            <InputGroupInput name='password' id='password' type={isVisible ? 'text' : 'password'} placeholder='••••••••••••••••' required />
            <InputGroupAddon align='inline-end' className='pr-1.5'>
              <Button
                type='button'
                variant='ghost'
                size='icon'
                onClick={() => setIsVisible(prevState => !prevState)}
                className='text-muted-foreground rounded-l-none hover:bg-transparent'
              >
                {isVisible ? <EyeOffIcon /> : <EyeIcon />}
                <span className='sr-only'>{isVisible ? 'Hide password' : 'Show password'}</span>
              </Button>
            </InputGroupAddon>
          </InputGroup>
        </Field>
        {/* Remember Me and Forgot Password */}
        <div className='flex items-center justify-between gap-y-2'>
          <Field orientation='horizontal' className='flex items-center gap-2'>
            <Checkbox id='rememberMe' />
            <FieldLabel htmlFor='rememberMe' className='text-muted-foreground'>
              {' '}
              Remember Me
            </FieldLabel>
          </Field>
          <Link href='/pages/auth/forgot-password' className='text-base text-nowrap hover:underline'>
            Forgot Password?
          </Link>
        </div>
        <Field>
          <Button className='w-full' type='submit' disabled={busy}>
            Sign in to CBMS
          </Button>
        </Field>
        {notice && <p role='status' className='text-muted-foreground text-sm'>{notice}</p>}
      </FieldGroup>
    </form>
  )
}

export default LoginForm
