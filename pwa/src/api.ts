const BASE = '/api/v1'

async function getCsrfCookie(): Promise<void> {
  await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' })
}

function getCsrfToken(): string {
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : ''
}

export async function apiFetch<T>(
  path: string,
  options: RequestInit & { etag?: string } = {},
): Promise<{ data: T; etag?: string; notModified?: boolean }> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    ...(options.headers as Record<string, string>),
  }

  if (options.etag) {
    headers['If-None-Match'] = options.etag
  }

  if (options.method && options.method !== 'GET') {
    headers['Content-Type'] = 'application/json'
    headers['X-XSRF-TOKEN'] = getCsrfToken()
  }

  const res = await fetch(`${BASE}${path}`, {
    credentials: 'same-origin',
    ...options,
    headers,
  })

  if (res.status === 304) {
    return { data: null as any, notModified: true }
  }

  if (!res.ok) {
    const body = await res.json().catch(() => ({}))
    throw Object.assign(new Error(body.message || `HTTP ${res.status}`), {
      status: res.status,
      body,
    })
  }

  const data = await res.json()
  return {
    data: data.data ?? data,
    etag: res.headers.get('ETag') || undefined,
  }
}

export async function apiMutate<T>(
  method: string,
  path: string,
  body?: any,
): Promise<T> {
  await getCsrfCookie()
  const res = await apiFetch<T>(path, {
    method,
    body: body ? JSON.stringify(body) : undefined,
  })
  return res.data
}
