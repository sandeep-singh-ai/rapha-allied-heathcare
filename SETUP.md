# Rapha Allied Health: GitHub → Coolify → Hostinger

This is a deployable **reference application** containing the supplied HTML site and a PHP/Microsoft 365 SMTP OAuth2 endpoint. It is not a live-connected deployment.

## Files
- `index.html`: site with four integrated forms (Contact, Appointment, Referral + attachments, Symptom Checker)
- `api/submit.php`: secure server-side email delivery
- `api/composer.json`: PHPMailer dependency
- `Dockerfile`: Apache + PHP 8.3 + Composer dependencies
- `.env.example`: environment variable names (placeholders, not real credentials)

## Before use
This package contains the earlier submitted site HTML. If your current GitHub repository has newer changes, **do not overwrite your live index.html**. Merge form handlers from the packaged `index.html` and the `api` directory/Dockerfile into the current repository instead. If your repo uses its own Dockerfile or a different framework, integrate the PHP backend accordingly rather than replacing the application. Commit Dockerfile, index.html, api/submit.php, api/composer.json, and .dockerignore. Do not commit real secrets.

## Coolify installation
1. Back up the production app and test in a Coolify preview/staging environment first.
2. Add these files to your existing GitHub repo, preserving the existing site's images/assets/routes. The included site is one standalone HTML page; verify that all routes render as intended.
3. In Coolify choose the GitHub repository/branch, **Build Pack / Build Strategy: Dockerfile**, **Base Directory: /**, **Dockerfile Location: /Dockerfile** (adjust if using a subdirectory), and **Ports Exposes: 80**.
4. Add the application domain `https://raphaalliedhealth.com` in Coolify's domains, with HTTPS configured.
5. In Coolify > Environment Variables add these as **runtime** (not build-time) variables:
    - RAPHA_SITE_ORIGIN=https://raphaalliedhealth.com
    - RAPHA_SENDER=info@raphaalliedhealth.com
    - RAPHA_RECIPIENT=info@raphaalliedhealth.com
    - MS365_TENANT_ID=<tenant-id>
    - MS365_CLIENT_ID=<client-id>
    - MS365_CLIENT_SECRET=<client-secret> (mark secret/locked in Coolify; never commit it)
6. Deploy and watch build logs, then application logs. `GET /api/submit.php` should respond **405**; if it downloads PHP source, immediately disable the deployment.
7. Confirm that the Hostinger server allows outbound TCP 587 to smtp.office365.com.

## Microsoft 365 administrator steps
1. Register a confidential app in Microsoft Entra (Azure) > App registrations.
2. Add **Office 365 Exchange Online → Application permissions → SMTP.SendAsApp**; grant tenant admin consent.
3. Create an application secret (store it in Coolify, never GitHub). Configure the Exchange Online service principal **using the Enterprise Application/service principal Object ID, not the app registration Object ID**. Grant designated mailbox access and SendAs permission as Microsoft documents.
4. Ensure authenticated SMTP is allowed for this mailbox and applicable tenant policy. Use the scope `https://outlook.office365.com/.default` (already in backend).
5. Validate via a test submission and Exchange message trace. 

Microsoft docs: https://learn.microsoft.com/en-us/exchange/client-developer/legacy-protocols/how-to-authenticate-an-imap-pop-smtp-application-by-using-oauth/

Coolify docs: https://coolify.io/docs/applications/builds/dockerfile and https://coolify.io/docs/applications/configuration/environment-variables

## Important safety and production readiness
- Referral and symptom information may contain health information. Email is not end-to-end encrypted: confirm your privacy notice, consent, access policies, and the appropriate secure processing/retention approach for Australian privacy obligations.
- Before public rollout, add verified Turnstile/CAPTCHA, WAF, monitoring and durable/shared rate limiting. Existing basic rate limiting is per container and may reset on redeploy.
- Ensure attachments are allowed by your security/privacy practices. Maximum currently: 5 referral attachments, 10 MB per file and 15 MB total attachments; HTTP body limit is 16 MB.
- Auto-replies are disabled. The backend sends only to `info@raphaalliedhealth.com`.
- Staging with a different domain requires setting `RAPHA_SITE_ORIGIN` to the matching staging origin.
- Without authorized admin access, no Microsoft 365 connection or actual deliverability test has been made.
