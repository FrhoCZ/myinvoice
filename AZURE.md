# Deploying MyInvoice.cz on Azure Functions

This guide walks through running MyInvoice.cz on **Azure Functions** using the
[Custom Handlers](https://learn.microsoft.com/en-us/azure/azure-functions/functions-custom-handlers)
feature, where PHP acts as the HTTP backend and the Functions runtime proxies
requests to it.

> **Lowest cost option**: Functions (Consumption) + MySQL Serverless costs
> **~€2–8/month** — you pay only when the app is actively used. See
> [Scenario A — Pay-per-use](#scenario-a--pay-per-use-recommended) below.

> **Tip — simpler alternative**: If you prefer a fully managed web host without
> serverless constraints, **Azure App Service** (Linux, PHP 8.2 stack) can run
> the existing Docker image with zero code changes. See the final section.

---

## Table of Contents

1. [Architecture overview](#architecture-overview)
2. [Pricing estimates](#pricing-estimates)
3. [Prerequisites](#prerequisites)
4. [Azure resources setup](#azure-resources-setup)
5. [Configuration — environment variables](#configuration--environment-variables)
6. [Deploy via GitHub Actions](#deploy-via-github-actions)
7. [Deploy manually (Azure CLI)](#deploy-manually-azure-cli)
8. [Cron jobs on Azure](#cron-jobs-on-azure)
9. [Storage & file uploads](#storage--file-uploads)
10. [Monitoring & logs](#monitoring--logs)
11. [Alternative: Azure App Service](#alternative-azure-app-service)

---

## Architecture overview

```
Internet → Azure Functions (HTTP Trigger, catch-all route)
               │  Custom Handler: azure-start.sh
               │  starts PHP built-in server on $FUNCTIONS_CUSTOMHANDLER_PORT
               ▼
          PHP / Slim 4 app  ←→  Azure Database for MySQL
                            ←→  Azure Cache for Redis   (optional, recommended)
                            ←→  SMTP relay              (e.g. Azure Communication Services)
```

Key files added to the repo:

| File | Purpose |
|------|---------|
| `host.json` | Azure Functions host config; declares Custom Handler |
| `HttpTrigger/function.json` | Catch-all HTTP trigger that forwards every request to PHP |
| `azure-start.sh` | Startup script — installs deps, links config, runs migrations, starts PHP |
| `cfg.azure.php` | Config template that reads all secrets from environment variables |
| `.github/workflows/azure-functions.yml` | CI/CD pipeline |

---

## Pricing estimates

All prices are **West Europe** region, pay-as-you-go, as of mid-2025.
Actual bills depend on usage. Use the
[Azure Pricing Calculator](https://azure.microsoft.com/pricing/calculator/) to model your own scenario.

### Scenario A — Pay-per-use (recommended)

The cheapest option. **You pay only when the app is actively used** — both the
compute and the database pause automatically during idle periods.

| Service | SKU | Est. monthly cost |
|---------|-----|-------------------|
| Azure Functions | Consumption plan — first 1M requests/month free, then ~€0.17/M | **€0** |
| Azure Database for MySQL | Flexible Server — **Serverless** (auto-pause after 1h idle), 2 vCore max, 20 GB | **~€2 – €8** |
| Storage account (required by Functions) | LRS, 5 GB | **~€0.10** |
| **Total** | | **~€2 – €8 / month** |

**Trade-offs:**
- First request after an idle period takes **10–30 seconds** while the database
  resumes (subsequent requests are fast). Acceptable for personal or low-traffic use.
- No Redis — sessions and rate-limit state use the database (automatic fallback).
- Compute is always free within 1M requests/month; the database vCore-seconds
  are billed only while queries are running.

**Create MySQL in Serverless mode** (replaces the Burstable command in step 4):

```bash
az mysql flexible-server create \
  --name myinvoice-db \
  --resource-group myinvoice-rg \
  --location westeurope \
  --admin-user myinvoiceadmin \
  --admin-password "<strong-password>" \
  --tier GeneralPurpose \
  --sku-name Standard_D2ads_v5 \
  --storage-size 20 \
  --version 8.0

# Enable storage auto-grow
az mysql flexible-server update \
  --name myinvoice-db \
  --resource-group myinvoice-rg \
  --storage-auto-grow Enabled

# Note: Auto-pause and compute auto-scaling must be configured via the
# Azure portal. Use the portal to enable "Compute auto-scale" and set
# "Auto-pause delay" to 60 minutes under Compute + storage settings.
```

> **Tip**: MySQL Serverless auto-pause is configured in the Azure portal under
> the server → **Compute + storage** → enable **Compute auto-scale** and set
> **Auto-pause delay** to 60 minutes.

---

### Scenario B — Always-on solo (no cold starts)

| Service | SKU | Est. monthly cost |
|---------|-----|-------------------|
| Azure Functions | Consumption plan | **€0 – €1** |
| Azure Database for MySQL | Flexible Server — B1ms (1 vCore, 2 GB RAM, 20 GB storage) | **~€13** |
| Storage account | LRS, 5 GB | **~€0.10** |
| **Total** | | **~€13 – €14 / month** |

Database runs 24/7 — no cold-start delay. Redis is omitted; add Basic C0
(~€14/month) if you want faster sessions and rate-limit counters under load.

---

### Scenario C — Small team (3–5 users, moderate usage)

| Service | SKU | Est. monthly cost |
|---------|-----|-------------------|
| Azure Functions | Consumption plan | **€1 – €5** |
| Azure Database for MySQL | Flexible Server — B2ms (2 vCore, 4 GB RAM, 32 GB storage) | **~€32** |
| Azure Cache for Redis | Standard C1 (1 GB, replication) | **~€60** |
| Storage account | LRS, 20 GB | **~€0.40** |
| Azure Communication Services (email) | 10k emails/month free | **€0** |
| **Total** | | **~€93 – €97 / month** |

---

### Scenario D — App Service instead of Functions (always-on, no cold starts)

| Service | SKU | Est. monthly cost |
|---------|-----|-------------------|
| Azure App Service | B1 (1 core, 1.75 GB RAM) | **~€13** |
| Azure Database for MySQL | B1ms | **~€13** |
| Azure Cache for Redis | Basic C0 | **~€14** |
| **Total** | | **~€40 / month** |

App Service avoids cold-start latency and is simpler for a long-lived web app.
See [Alternative: Azure App Service](#alternative-azure-app-service).

---

## Prerequisites

- Azure subscription (free tier works for testing)
- [Azure CLI](https://learn.microsoft.com/cli/azure/install-azure-cli) installed
- PHP 8.2+ with extensions: `pdo_mysql`, `mbstring`, `intl`, `gd`, `zip`
- Node.js 22+ and pnpm 10+
- [Azure Functions Core Tools v4](https://learn.microsoft.com/azure/azure-functions/functions-run-local)

---

## Azure resources setup

### 1. Login and create a resource group

```bash
az login
az account set --subscription "<your-subscription-id>"

az group create \
  --name myinvoice-rg \
  --location westeurope
```

### 2. Create a storage account (required by Functions)

```bash
az storage account create \
  --name myinvoicestorage \
  --resource-group myinvoice-rg \
  --location westeurope \
  --sku Standard_LRS
```

### 3. Create the Function App

```bash
az functionapp create \
  --name myinvoice-func \
  --resource-group myinvoice-rg \
  --storage-account myinvoicestorage \
  --consumption-plan-location westeurope \
  --runtime custom \
  --runtime-version "" \
  --functions-version 4 \
  --os-type Linux
```

### 4. Create Azure Database for MySQL (Flexible Server)

**Option A — Serverless (pay-per-use, recommended for personal use)**

Auto-pauses after 1 hour of inactivity. First request after a pause takes
10–30 seconds while the database resumes.

```bash
az mysql flexible-server create \
  --name myinvoice-db \
  --resource-group myinvoice-rg \
  --location westeurope \
  --admin-user myinvoiceadmin \
  --admin-password "<strong-password>" \
  --tier GeneralPurpose \
  --sku-name Standard_D2ads_v5 \
  --storage-size 20 \
  --version 8.0
```

After creation, enable auto-pause in the Azure portal:
**myinvoice-db → Compute + storage → Compute auto-scale → Auto-pause delay: 60 min**

**Option B — Always-on Burstable (no cold starts, ~€13/month)**

```bash
az mysql flexible-server create \
  --name myinvoice-db \
  --resource-group myinvoice-rg \
  --location westeurope \
  --admin-user myinvoiceadmin \
  --admin-password "<strong-password>" \
  --sku-name Standard_B1ms \
  --tier Burstable \
  --storage-size 20 \
  --version 8.0
```

Create the application database:

```bash
az mysql flexible-server db create \
  --resource-group myinvoice-rg \
  --server-name myinvoice-db \
  --database-name myinvoice
```

Allow the Function App's outbound IPs (or enable the VNet integration):

```bash
# Quick option: allow Azure services (less secure, fine for early testing)
az mysql flexible-server firewall-rule create \
  --resource-group myinvoice-rg \
  --name myinvoice-db \
  --rule-name allow-azure-services \
  --start-ip-address 0.0.0.0 \
  --end-ip-address 0.0.0.0
```

Download the SSL CA bundle required by Azure MySQL and include it in your deployment:

```bash
# Download the certificate to your project root
curl -o DigiCertGlobalRootG2.crt.pem \
  https://dl.cacerts.digicert.com/DigiCertGlobalRootG2.crt.pem
```

**Important**: Bundle the certificate file in your deployment package (place it at
the project root or in the `api/` directory). Azure Functions uses an ephemeral
filesystem — files uploaded separately may be lost on instance recycle. Set
`MYINVOICE_DB_SSL_CA` to the absolute path where the file will exist after
deployment, e.g., `/home/site/wwwroot/DigiCertGlobalRootG2.crt.pem`.

### 5. Create Azure Cache for Redis (optional)

```bash
az redis create \
  --name myinvoice-redis \
  --resource-group myinvoice-rg \
  --location westeurope \
  --sku Basic \
  --vm-size C0
```

Get the access key:

```bash
az redis list-keys \
  --name myinvoice-redis \
  --resource-group myinvoice-rg \
  --query primaryKey -o tsv
```

---

## Configuration — environment variables

Set all secrets as Application Settings on the Function App.
**Never commit secrets to git.**

```bash
az functionapp config appsettings set \
  --name myinvoice-func \
  --resource-group myinvoice-rg \
  --settings \
    MYINVOICE_APP_URL="https://myinvoice-func.azurewebsites.net" \
    MYINVOICE_APP_PEPPER="$(openssl rand -base64 32)" \
    MYINVOICE_APP_SECRET_KEY="$(openssl rand -base64 32)" \
    MYINVOICE_DB_HOST="myinvoice-db.mysql.database.azure.com" \
    MYINVOICE_DB_NAME="myinvoice" \
    MYINVOICE_DB_USER="myinvoiceadmin" \
    MYINVOICE_DB_PASS="<strong-password>" \
    MYINVOICE_DB_SSL_CA="/home/site/wwwroot/DigiCertGlobalRootG2.crt.pem" \
    MYINVOICE_REDIS_HOST="myinvoice-redis.redis.cache.windows.net" \
    MYINVOICE_REDIS_PORT="6380" \
    MYINVOICE_REDIS_AUTH="<redis-primary-key>" \
    MYINVOICE_SMTP_HOST="smtp.example.com" \
    MYINVOICE_SMTP_PORT="587" \
    MYINVOICE_SMTP_USER="noreply@example.com" \
    MYINVOICE_SMTP_PASS="<smtp-password>" \
    MYINVOICE_SMTP_FROM="noreply@example.com"
```

### Full variable reference

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `MYINVOICE_APP_URL` | ✅ | — | Public HTTPS base URL |
| `MYINVOICE_APP_PEPPER` | ✅ | — | 32-byte base64 password pepper |
| `MYINVOICE_APP_SECRET_KEY` | | `""` | 32-byte base64 AES key for TOTP |
| `MYINVOICE_APP_ENV` | | `production` | `production` or `development` |
| `MYINVOICE_TIMEZONE` | | `Europe/Prague` | PHP timezone |
| `MYINVOICE_LOCALE` | | `cs` | Default UI language |
| `MYINVOICE_DB_HOST` | ✅ | — | MySQL hostname |
| `MYINVOICE_DB_NAME` | ✅ | — | Database name |
| `MYINVOICE_DB_USER` | ✅ | — | Database user |
| `MYINVOICE_DB_PASS` | ✅ | — | Database password |
| `MYINVOICE_DB_PORT` | | `3306` | Database port |
| `MYINVOICE_DB_SSL_CA` | | `""` | Path to SSL CA (required on Azure MySQL) |
| `MYINVOICE_REDIS_HOST` | | `""` | Redis hostname (empty = disable Redis) |
| `MYINVOICE_REDIS_PORT` | | `6380` | Redis port |
| `MYINVOICE_REDIS_AUTH` | | `null` | Redis access key |
| `MYINVOICE_SMTP_HOST` | ✅ | — | SMTP server hostname |
| `MYINVOICE_SMTP_PORT` | | `587` | SMTP port |
| `MYINVOICE_SMTP_ENCRYPTION` | | `tls` | `tls`, `ssl`, or `""` |
| `MYINVOICE_SMTP_AUTH_TYPE` | | `LOGIN` | `LOGIN`, `PLAIN`, `CRAM-MD5` |
| `MYINVOICE_SMTP_USER` | ✅ | — | SMTP username |
| `MYINVOICE_SMTP_PASS` | ✅ | — | SMTP password |
| `MYINVOICE_SMTP_FROM` | ✅ | — | Sender email address |
| `MYINVOICE_SMTP_FROM_NAME` | | `MyInvoice` | Sender display name |
| `MYINVOICE_STORAGE_INVOICES` | | `/tmp/myinvoice/invoices` | PDF output directory |
| `MYINVOICE_STORAGE_UPLOADS` | | `/tmp/myinvoice/uploads` | Upload temp directory |
| `MYINVOICE_STORAGE_BACKUP` | | `/tmp/myinvoice/backup` | Backup directory |
| `MYINVOICE_STORAGE_SESSIONS` | | `/tmp/myinvoice/sessions` | Sessions directory |
| `MYINVOICE_STORAGE_CACHE` | | `/tmp/myinvoice/cache` | File cache directory |
| `MYINVOICE_LOG_PATH` | | `/tmp/myinvoice/log/app.log` | Application log file |
| `MYINVOICE_LOG_LEVEL` | | `info` | `debug`, `info`, `warning`, `error` |
| `MYINVOICE_TURNSTILE_SITE_KEY` | | `""` | Cloudflare Turnstile public key |
| `MYINVOICE_TURNSTILE_SECRET_KEY` | | `""` | Cloudflare Turnstile secret key |
| `MYINVOICE_SESSION_COOKIE_NAME` | | `__Host-myinvoice_session` | Session cookie name |

> **Storage note**: Azure Functions uses an ephemeral filesystem. Files written
> to `/tmp` are lost on instance recycle. Generated PDFs are always regenerated
> on demand, so this is fine. Bank statement uploads (`.gpc` files) should be
> processed immediately and not relied on for long-term storage.
> For persistent uploads, integrate Azure Blob Storage.

---

## Deploy via GitHub Actions

The workflow at `.github/workflows/azure-functions.yml` builds the frontend,
installs PHP dependencies, and deploys to Azure Functions on every push to `main`.

### Setup

1. **Get a publish profile** from the Azure portal:
   - Open the Function App → **Overview** → **Get publish profile** → download the file.

2. **Add it as a GitHub secret**:
   - Repository → **Settings** → **Secrets and variables** → **Actions** → **New repository secret**
   - Name: `AZURE_FUNCTIONAPP_PUBLISH_PROFILE`
   - Value: paste the entire content of the downloaded `.PublishSettings` file.

3. **Add the Function App name as a variable**:
   - Same location as secrets, but under **Variables**
   - Name: `AZURE_FUNCTIONAPP_NAME`, Value: `myinvoice-func`

4. Push to `main` — the workflow deploys automatically.

---

## Deploy manually (Azure CLI)

Build and deploy without GitHub Actions:

```bash
# 1. Build frontend
cd web && pnpm install && pnpm build && cd ..
cp -r web/dist/. api/public/

# 2. Install PHP dependencies
composer install --no-dev --optimize-autoloader --working-dir=api

# 3. Package and deploy
zip -r deploy.zip . \
  --exclude "web/node_modules/*" \
  --exclude "web/src/*" \
  --exclude ".git/*" \
  --exclude "*.local.*"

az functionapp deployment source config-zip \
  --name myinvoice-func \
  --resource-group myinvoice-rg \
  --src deploy.zip
```

---

## Cron jobs on Azure

The app ships with four cron scripts in `api/bin/`. On Azure Functions you can
run them as **Timer Triggers** by adding extra function definitions.

### Example: daily cleanup

Create `CronCleanup/function.json`:

```json
{
  "bindings": [
    {
      "name": "timer",
      "type": "timerTrigger",
      "direction": "in",
      "schedule": "0 0 2 * * *"
    }
  ]
}
```

And handle the timer in `azure-start.sh` or via a separate custom handler
endpoint that the Functions runtime POSTs to. The simpler approach on a
Consumption plan is to call cron scripts via a dedicated HTTP endpoint
protected by a secret header, and trigger them from Azure Logic Apps or
GitHub Actions on a schedule.

### Quick approach — scheduled GitHub Actions

```yaml
# .github/workflows/cron.yml
on:
  schedule:
    - cron: '0 2 * * *'   # 02:00 UTC daily

jobs:
  cleanup:
    runs-on: ubuntu-latest
    steps:
      - name: Trigger cleanup
        run: |
          curl -sf -X POST \
            -H "X-Cron-Secret: ${{ secrets.CRON_SECRET }}" \
            "https://myinvoice-func.azurewebsites.net/api/cron/cleanup"
```

Add the `CRON_SECRET` both to GitHub secrets and to the Function App settings
(`MYINVOICE_CRON_SECRET`), then guard the cron endpoints in the PHP app.

---

## Storage & file uploads

The default configuration stores PDFs and uploads in `/tmp`, which is ephemeral
on Azure Functions. This works because:

- **PDFs** are regenerated on demand — the database row is the source of truth.
- **GPC bank statement uploads** are parsed immediately and the raw file is
  discarded after import.

If you need to retain uploaded files across instances (e.g., for re-download),
integrate **Azure Blob Storage** using the
[Azure SDK for PHP](https://github.com/Azure/azure-sdk-for-php) and adjust the
storage service classes in `api/src/Service/`.

---

## Monitoring & logs

- Application Insights is pre-configured in `host.json` (enable it in the portal).
- PHP logs are written to the path in `MYINVOICE_LOG_PATH` and to stderr
  (captured by the Functions runtime).
- View live logs: `az functionapp log tail --name myinvoice-func --resource-group myinvoice-rg`

---

## Alternative: Azure App Service

For a traditional always-on deployment without serverless cold-starts, use the
existing Docker image on **Azure App Service**:

```bash
# Create an App Service Plan (B1 = ~€13/month)
az appservice plan create \
  --name myinvoice-plan \
  --resource-group myinvoice-rg \
  --sku B1 \
  --is-linux

# Create the Web App from the published Docker image
az webapp create \
  --name myinvoice-app \
  --resource-group myinvoice-rg \
  --plan myinvoice-plan \
  --deployment-container-image-name ghcr.io/frhocz/myinvoice:latest

# Set the same environment variables as above
az webapp config appsettings set \
  --name myinvoice-app \
  --resource-group myinvoice-rg \
  --settings \
    MYINVOICE_APP_URL="https://myinvoice-app.azurewebsites.net" \
    ...
```

The Docker image uses Apache and includes all PHP extensions. No `cfg.php`
changes are needed — mount `cfg.php` as an App Service file or use the same
`cfg.azure.php` approach by setting `WEBSITES_ENABLE_APP_SERVICE_STORAGE=true`
and uploading the file.

> See the [Docker deployment guide](cmd/README.md) for full details.
