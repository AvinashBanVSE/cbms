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

  test('redirects unauthenticated users to sign in', async ({ page }) => {
    await page.route('**/api/v1/installation', async route => {
      await route.fulfill({ status: 401, contentType: 'application/json', body: '{}' })
    })

    await page.goto('/dashboard')
    await expect(page).toHaveURL(/\/pages\/auth\/login$/)
  })
})
