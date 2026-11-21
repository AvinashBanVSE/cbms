import { expect, test } from '@playwright/test'

test.describe('CBMS overview dashboard', () => {
  test('shows authorized installation and workspace data from the API', async ({ page }) => {
    await page.route('**/api/v1/installation', async route => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ stage: 'ready' })
      })
    })
    await page.route('**/api/v1/workspace-access', async route => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          administration: ['business_directory', 'organization_onboarding'],
          mapping_contexts: [{ business_kind: 'organization' }],
          delegations: []
        })
      })
    })

    await page.goto('/dashboard')

    await expect(page.getByRole('heading', { name: 'System overview' })).toBeVisible()
    await expect(page.getByText('Installation stage: ready')).toBeVisible()
    await expect(page.getByText(/Authorized administration areas: 2/)).toBeVisible()
    await expect(page.getByText(/mapping contexts: 1/)).toBeVisible()
    await expect(page.getByText(/template orders/i)).not.toBeVisible()
  })

  test('completes the authenticator challenge during sign-in', async ({ page }) => {
    let attempts = 0

    await page.route('**/api/v1/csrf', async route => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ token: 'test-token' }) })
    })
    await page.route('**/api/v1/login', async route => {
      attempts += 1
      await route.fulfill({
        status: attempts === 1 ? 202 : 200,
        contentType: 'application/json',
        body: JSON.stringify({ stage: attempts === 1 ? 'authenticator_required' : 'authenticated' })
      })
    })
    await page.route('**/api/v1/installation', async route => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ stage: 'ready' }) })
    })
    await page.route('**/api/v1/workspace-access', async route => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ administration: [], mapping_contexts: [], delegations: [] }) })
    })

    await page.goto('/pages/auth/login')
    await page.getByLabel('User Code*').fill('AA0000')
    await page.getByLabel('Email address*').fill('owner@example.com')
    await page.getByLabel('Password*').fill('password')
    await page.getByRole('button', { name: 'Sign in to CBMS' }).click()
    await expect(page.getByLabel('Authenticator code*')).toBeVisible()
    await page.getByLabel('Authenticator code*').fill('123456')
    await page.getByRole('button', { name: 'Sign in to CBMS' }).click()
    await expect(page).toHaveURL(/\/dashboard$/)
  })

  test('redirects unauthenticated users to sign in', async ({ page }) => {
    await page.route('**/api/v1/workspace-access', async route => {
      await route.fulfill({ status: 401, contentType: 'application/json', body: '{}' })
    })

    await page.goto('/dashboard')
    await expect(page).toHaveURL(/\/pages\/auth\/login$/)
  })
})
