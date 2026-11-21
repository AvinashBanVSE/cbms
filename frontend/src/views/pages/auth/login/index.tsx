// Next Imports
import Link from 'next/link'

// Components Import
import Logo from '@/components/shared/Logo'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import LoginForm from '@/views/pages/auth/login/login-form'

// SVG Import
import AuthBackgroundShape from '@/assets/svg/auth-background-shape'

const Login = () => {
  return (
    <div className='relative flex h-auto min-h-screen items-center justify-center overflow-x-hidden px-4 py-10 sm:px-6 lg:px-8'>
      <div className='absolute'>
        <AuthBackgroundShape />
      </div>

      <Card className='z-1 w-full gap-6 py-6 sm:max-w-lg'>
        <CardHeader className='gap-6 px-6'>
          <Link href='/'>
            <Logo className='gap-3' />
          </Link>

          <div>
            <CardTitle className='mb-2 text-2xl font-semibold'>Sign in to CBMS</CardTitle>
            <CardDescription className='text-base'>Use your assigned User Code and password.</CardDescription>
          </div>
        </CardHeader>

        <CardContent className='px-6'>
          <div className='space-y-4'>
            <LoginForm />

          </div>
        </CardContent>
      </Card>
    </div>
  )
}

export default Login
