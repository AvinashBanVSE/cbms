# CBM Portal

Laravel 13 serves the CBM API. The web client uses React and TypeScript.
The current development branch contains installation, the first Main Portal Owner account, browser sign-in and the authorized service category catalogue.

## Install the first portal

1. In `backend`, install dependencies with Composer under PHP 8.3 or later. Copy `.env.example` to `.env`, set the production database, HTTPS application URL, session storage, `APP_ENV=production`, `APP_DEBUG=false` and `SESSION_SECURE_COOKIE=true`. Configure shared session and cache storage for multiple application instances. Generate the application encryption key with `php artisan key:generate`.
2. On the deployment machine generate a random 32-byte secret. For example, run `openssl rand -hex 32` in a private terminal. Give the secret to the authorized installer. Store only its SHA-256 hex digest in `CBM_SETUP_CODE_SHA256`. Compute the digest over the literal secret text without a trailing newline. Run `php artisan migrate --force`.
3. Build the client in `frontend` with `npm install` and `npm run build`. Serve its `dist` folder and route `/api/` on the same HTTPS origin to Laravel's `public/index.php`.
4. Open the portal. Enter the legal and trading names and the deployment secret. On the next screen enter the owner's name, email, permanent password and deployment secret. Save the assigned user code.
5. Sign in using the user code, email and password. The first account has an explicit permission to view the Main Portal service category catalogue. Remove the setup digest from deployment configuration and refresh Laravel's configuration cache. The database stage also prevents reuse after owner creation.
6. Run Laravel's scheduler in the deployment environment by invoking `php artisan schedule:run` from `backend` once a minute. Automatic Subscription renewal runs every five minutes. A renewal invoice is issued according to the lead time recorded on the term. Unsettled renewals follow their configured grace period and feature policy. Full activation requires the start date and invoice settlement. Reinstatement after suspension requires an authorized action.

## Verification

Run `composer test:coverage` from the backend with a PHP coverage driver enabled. Run `npm test` and `npm run build` from the frontend. Each coverage threshold is 90%.
