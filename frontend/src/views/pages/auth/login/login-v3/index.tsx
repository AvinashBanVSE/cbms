// Next Imports
import Link from 'next/link'

// Component Import
import Logo from '@/components/shared/Logo'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import Silk from '@/components/ui/bg-silk'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import LoginV3Form from '@/views/pages/auth/login/login-v3/login-v3-form'

// SVG Import
import LogoVector from '@/assets/svg/logo-vector'

const LoginV3 = () => {
  return (
    <div className='h-dvh lg:grid lg:grid-cols-2'>
      <div className='flex h-full items-center justify-center space-y-6 sm:px-6 md:px-8'>
        <div className='flex w-full flex-col gap-6 p-6 sm:max-w-lg'>
          <Link href='/'>
            <Logo className='gap-3' />
          </Link>

          <div>
            <h2 className='mb-2 text-2xl font-semibold'>Sign in to CBM Portal</h2>
            <p className='text-muted-foreground'>Enter your User Code, email address and password to continue.</p>
          </div>

          <div className='space-y-4'>
            {/* Login Form */}
            <LoginV3Form />
          </div>
        </div>
      </div>

      <div className='bg-muted h-screen p-5 max-lg:hidden'>
        <Card className='relative h-full justify-between overflow-hidden border-none py-8'>
          <div className='pointer-events-none absolute inset-0 z-0'>
            <Silk speed={10} scale={1} color='#7B7481' noiseIntensity={0} rotation={8} />
          </div>

          <div className='pointer-events-none absolute inset-0 z-1' />

          <CardHeader className='relative z-10 gap-6 px-8'>
            <CardTitle className='text-4xl font-bold text-white xl:text-5xl/15.5'>Welcome back!</CardTitle>
            <p className='text-xl text-white'>
              Thank you for registering! Please check your inbox and click the verification link to activate your
              account.
            </p>
          </CardHeader>

          <CardContent className='relative z-10 mx-8 h-62 overflow-hidden rounded-2xl px-0'>
            <svg
              width='1094'
              height='249'
              viewBox='0 0 1094 249'
              fill='none'
              xmlns='http://www.w3.org/2000/svg'
              className='pointer-events-none absolute right-0 -z-1 select-none'
            >
              <path
                d='M0.263672 16.8809C0.263672 8.0443 7.42712 0.880859 16.2637 0.880859H786.394H999.115C1012.37 0.880859 1023.12 11.626 1023.12 24.8808L1023.12 47.3809C1023.12 60.6357 1033.86 71.3809 1047.12 71.3809H1069.6C1082.85 71.3809 1093.6 82.126 1093.6 95.3809L1093.6 232.881C1093.6 241.717 1086.43 248.881 1077.6 248.881H16.2637C7.42716 248.881 0.263672 241.717 0.263672 232.881V16.8809Z'
                fill='#ffff'
              />
            </svg>

            <div className='absolute top-0 right-0 flex size-15 items-center justify-center rounded-2xl bg-[#F5F5F5]'>
              <LogoVector className='size-15 text-black' />
            </div>

            <div className='flex flex-col gap-5 p-6 text-black'>
              <p className='line-clamp-2 pr-12 text-3xl font-bold'>Secure portal sign-in</p>
              <p className='line-clamp-2 text-lg'>
                Use your assigned User Code and registered credentials to access the portal.
              </p>

            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  )
}

export default LoginV3
