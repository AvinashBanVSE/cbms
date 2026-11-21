export const navigationCookie = 'cbms_opaque_navigation'
export const navigationLifetimeSeconds = 15 * 60

export const pageKeys = [
  'login', 'setup', 'dashboard', 'dashboard-orders', 'error', 'user-profile', 'user-settings',
  'calendar', 'mail', 'users-list', 'users-view', 'datatable', 'form-horizontal', 'form-vertical', 'form-validation'
] as const

export type PageKey = (typeof pageKeys)[number]

type NavigationEntry = { page: PageKey; expiresAt: number }
type NavigationMap = { entries: Record<string, NavigationEntry> }

const encoder = new TextEncoder()
const decoder = new TextDecoder()

function secret(): string {
  const value = process.env.OPAQUE_NAVIGATION_SECRET

  if (value) return value
  if (process.env.NODE_ENV === 'production') throw new Error('OPAQUE_NAVIGATION_SECRET is required in production.')

  return 'cbms-development-only-opaque-navigation-secret'
}

function encodeBase64Url(bytes: Uint8Array): string {
  let binary = ''

  bytes.forEach(byte => { binary += String.fromCharCode(byte) })

  return btoa(binary).replaceAll('+', '-').replaceAll('/', '_').replaceAll('=', '')
}

function decodeBase64Url(value: string): Uint8Array {
  const padded = value.replaceAll('-', '+').replaceAll('_', '/').padEnd(Math.ceil(value.length / 4) * 4, '=')
  const binary = atob(padded)

  return Uint8Array.from(binary, character => character.charCodeAt(0))
}

async function signingKey(): Promise<CryptoKey> {
  return crypto.subtle.importKey('raw', encoder.encode(secret()), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign', 'verify'])
}

export async function sealNavigationMap(value: NavigationMap): Promise<string> {
  const payload = encoder.encode(JSON.stringify(value))
  const signature = new Uint8Array(await crypto.subtle.sign('HMAC', await signingKey(), payload))

  return `${encodeBase64Url(payload)}.${encodeBase64Url(signature)}`
}

export async function openNavigationMap(value?: string): Promise<NavigationMap> {
  if (!value) return { entries: {} }

  try {
    const [encodedPayload, encodedSignature] = value.split('.')

    if (!encodedPayload || !encodedSignature) return { entries: {} }
    const payload = decodeBase64Url(encodedPayload)
    const signature = decodeBase64Url(encodedSignature)

    if (!await crypto.subtle.verify('HMAC', await signingKey(), signature as BufferSource, payload as BufferSource)) return { entries: {} }
    const parsed = JSON.parse(decoder.decode(payload)) as NavigationMap

    return parsed?.entries ? parsed : { entries: {} }
  } catch {
    return { entries: {} }
  }
}

export function uuidV7(): string {
  const bytes = crypto.getRandomValues(new Uint8Array(16))
  const timestamp = Date.now()

  for (let index = 5; index >= 0; index -= 1) bytes[index] = Math.floor(timestamp / 2 ** ((5 - index) * 8)) & 0xff
  bytes[6] = (bytes[6] & 0x0f) | 0x70
  bytes[8] = (bytes[8] & 0x3f) | 0x80
  const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('')

  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

export function pruneNavigationMap(map: NavigationMap): NavigationMap {
  const now = Date.now()
  const entries = Object.fromEntries(Object.entries(map.entries).filter(([, entry]) => entry.expiresAt > now).slice(-8))

  return { entries }
}

export function isPageKey(value: unknown): value is PageKey {
  return typeof value === 'string' && pageKeys.includes(value as PageKey)
}

export function addNavigationEntry(map: NavigationMap, page: PageKey): { map: NavigationMap; id: string } {
  const id = uuidV7()
  const pruned = pruneNavigationMap(map)

  pruned.entries[id] = { page, expiresAt: Date.now() + navigationLifetimeSeconds * 1000 }

  return { map: pruned, id }
}

export function resolveNavigationEntry(map: NavigationMap, id: string): PageKey | null {
  const entry = map.entries[id]

  return entry && entry.expiresAt > Date.now() ? entry.page : null
}
