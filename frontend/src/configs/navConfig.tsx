// Third-party Imports
import type * as Icon from 'lucide-react'

type IconName = keyof typeof Icon

export type MenuLeafSubItem = {
  label: string
  href: string
  activePath?: string
  badge?: string
  badgeClassName?: string
  target?: '_blank' | '_self' | '_parent' | '_top'
}

export type MenuGroupSubItem = {
  label: string
  childItems: MenuLeafSubItem[]
}

export type MenuSubItem = MenuLeafSubItem | MenuGroupSubItem

export type MenuItem = {
  icon: IconName
  label: string
} &
  (
    | {
        href: string
        badge?: string
        badgeClassName?: string
        childItems?: never
        target?: '_blank' | '_self' | '_parent' | '_top'
      }
    | {
        href?: never
        badge?: string
        badgeClassName?: string
        childItems: MenuSubItem[]
      }
  )

export type NavItem = {
  groupLabel?: string
  items: MenuItem[]
}

/**
 * Product workspaces are exposed only after their authorized API workflow is
 * available in this app. This prevents template demos from appearing as CBM.
 */
export const navItems: NavItem[] = [
  {
    groupLabel: 'Portal',
    items: [
      {
        icon: 'LayoutDashboardIcon',
        label: 'Overview',
        href: '/dashboard'
      }
    ]
  }
]
