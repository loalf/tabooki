import createClient from 'openapi-fetch'
import type { paths } from './schema'

/**
 * Typed API client. Types are generated from the Symfony/API Platform OpenAPI spec:
 * run `make api-types` after changing an API resource.
 *
 * The SPA and the API share one origin, so the session cookie is sent automatically.
 */
// Responses are always requested as plain JSON (not JSON-LD), so type them that way.
export const api = createClient<paths, 'application/json'>({
  baseUrl: '',
  credentials: 'same-origin',
  headers: { Accept: 'application/json' },
})

export class ApiError extends Error {
  readonly status: number

  constructor(status: number, message?: string) {
    super(message ?? `API error ${status}`)
    this.status = status
  }
}
