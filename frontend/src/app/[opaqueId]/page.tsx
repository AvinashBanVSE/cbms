import { cookies } from 'next/headers'
import { redirect } from 'next/navigation'

import Login from '@/views/pages/auth/login'
import InstallationSetup from '@/views/pages/setup/installation-setup'
import ErrorPage from '@/views/pages/misc/error-page'
import UserProfile from '@/views/pages/user-profile'
import UserSettings from '@/views/pages/user-settings'
import Dashboard from '@/views/dashboard/system-overview'
import { EventCalendar as Calendar } from '@/views/apps/calendar'
import Mail from '@/views/apps/mail'
import UsersList from '@/views/apps/users/list'
import UsersView from '@/views/apps/users/view'
import DataTable from '@/views/datatables'
import HorizontalForm from '@/views/forms/form-layouts/horizontal'
import VerticalForm from '@/views/forms/form-layouts/vertical'
import FormValidation from '@/views/forms/form-validation'
import PagesLayout from '@/app/(pages)/layout'
import { navigationCookie, openNavigationMap, resolveNavigationEntry } from '@/lib/opaque-navigation'

type Props = { params: Promise<{ opaqueId: string }> }

export default async function OpaquePage({ params }: Props) {
  const { opaqueId } = await params
  const store = await cookies()
  const page = resolveNavigationEntry(await openNavigationMap(store.get(navigationCookie)?.value), opaqueId)

  if (!page) redirect('/')

  if (page === 'login') return <Login />
  if (page === 'setup') return <InstallationSetup />
  if (page === 'error') return <ErrorPage />

  const content = (() => {
    switch (page) {
      case 'dashboard':
      case 'dashboard-orders': return <Dashboard />
      case 'user-profile': return <UserProfile />
      case 'user-settings': return <UserSettings />
      case 'calendar': return <Calendar />
      case 'mail': return <Mail />
      case 'users-list': return <UsersList />
      case 'users-view': return <UsersView />
      case 'datatable': return <DataTable />
      case 'form-horizontal': return <HorizontalForm />
      case 'form-vertical': return <VerticalForm />
      case 'form-validation': return <FormValidation />
      default: return null
    }
  })()

  return <PagesLayout>{content}</PagesLayout>
}
