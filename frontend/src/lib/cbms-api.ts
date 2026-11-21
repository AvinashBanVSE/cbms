export class CbmsApiError extends Error {
  constructor(
    message: string,
    readonly status: number
  ) {
    super(message)
  }
}

type RequestBody = Record<string, unknown>

const apiBase = process.env.NEXT_PUBLIC_API_BASE_URL ?? '/api/v1'

export async function cbmsRequest(path: string, body?: RequestBody): Promise<Response> {
  const headers: Record<string, string> = { Accept: 'application/json' }

  if (body) {
    const csrf = await fetch(`${apiBase}/csrf`, { credentials: 'include', cache: 'no-store' })

    if (!csrf.ok) throw new CbmsApiError('Session unavailable. Please retry.', csrf.status)
    const payload = (await csrf.json()) as { token?: unknown }

    if (typeof payload.token !== 'string' || payload.token.length === 0) {
      throw new CbmsApiError('Session unavailable. Please retry.', 419)
    }

    headers['Content-Type'] = 'application/json'
    headers['X-CSRF-TOKEN'] = payload.token
  }

  const response = await fetch(`${apiBase}/${path}`, {
    method: body ? 'POST' : 'GET',
    headers,
    credentials: 'include',
    cache: 'no-store',
    body: body ? JSON.stringify(body) : undefined
  })

  if (!response.ok) {
    const payload = (await response.json().catch(() => ({}))) as {
      message?: unknown
      errors?: Record<string, unknown>
    }

    const validation = Object.values(payload.errors ?? {}).flat().filter(value => typeof value === 'string')
    const message = validation.length > 0 ? validation.join(' ') : typeof payload.message === 'string' ? payload.message : 'Request failed. Please retry.'

    throw new CbmsApiError(message, response.status)
  }

  return response
}
