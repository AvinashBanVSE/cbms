// Next Imports
import Link from 'next/link'

// Third-party Imports
import { LogInIcon } from 'lucide-react'

// Component Imports
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'

const STATIC_USER = {
  fullName: 'Portal user',
  email: 'Account details are available after sign-in',
  imageUrl: '',
  initials: 'PU'
}

const ProfileDropdown = () => {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger
        render={<Button variant='ghost' size='icon' className='relative rounded-full hover:bg-transparent' />}
      >
        <Avatar>
          <AvatarImage src={STATIC_USER.imageUrl} alt={STATIC_USER.fullName} />
          <AvatarFallback>{STATIC_USER.initials}</AvatarFallback>
        </Avatar>
        <span className='ring-card absolute right-0 bottom-0 block size-2 rounded-full bg-green-600 ring-2' />
      </DropdownMenuTrigger>
      <DropdownMenuContent align='end' className='w-60'>
        <DropdownMenuLabel className='flex items-center gap-4 px-2 py-2.5 font-normal'>
          <div className='relative'>
            <Avatar className='size-10'>
              <AvatarImage src={STATIC_USER.imageUrl} alt={STATIC_USER.fullName} />
              <AvatarFallback>{STATIC_USER.initials}</AvatarFallback>
            </Avatar>
          </div>
          <div className='flex flex-1 flex-col items-start'>
            <span className='text-foreground text-base font-semibold'>{STATIC_USER.fullName}</span>
            <span className='text-muted-foreground text-sm'>{STATIC_USER.email}</span>
          </div>
        </DropdownMenuLabel>
        <DropdownMenuItem render={<Link href='/pages/auth/login' />}>
          <LogInIcon />
          <span>Sign in</span>
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

export default ProfileDropdown
