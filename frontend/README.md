# CBMS Frontend

This is the Next.js user interface for CBMS, the Centralized Business Management System.

## Design boundary

The frontend presents only confirmed CBMS workflows. It must not expose template demonstrations, sample business records, external template links, or fabricated account data as system functionality. Every operational screen uses the Laravel API as its source of truth.

The initial interface provides an English-only responsive portal shell, light and dark appearance support, and a compact navigation foundation. Additional workspaces are introduced only with their authorized API workflow, permission checks and tests.

## Local development

```bash
pnpm install --frozen-lockfile
pnpm dev
```

Use `pnpm check-types`, `pnpm lint`, and `pnpm build` before publishing frontend changes. API integration and environment configuration are added through the project deployment configuration rather than hard-coded URLs.

## Attribution

The interface foundation includes code from the AdminCN Free template under the MIT License. The full license text remains in [LICENSE.md](LICENSE.md).
