# Laravel 13 CRM Backend — Production Deployment & Readiness Guide

This guide details the deployment procedure for the **Laravel 13 CRM Backend REST API**, designed for secure integration with the independently deployed **React + TypeScript + Vite + Tailwind CSS** frontend on **Vercel**.

---

## 1. System Architecture & Topology

```
┌────────────────────────────────────────────────────────┐
│                   Vercel Edge Network                  │
│       Frontend: React 19 + TypeScript + Vite SPA       │
│    VITE_API_BASE_URL=https://<backend-domain>/api/v1   │
└───────────────────────────┬────────────────────────────┘
                            │
              HTTPS REST API│(Stateless Bearer Token Auth)
                            ▼
┌────────────────────────────────────────────────────────┐
│               Production Application Server            │
│       [VPS / Cloud Server]  OR  [Shared PHP Hosting]   │
│            Document Root: /path/to/backend/public      │
│            PHP 8.3+ Runtime, Composer 2.5+             │
│                                                        │
│ - Configurable CORS Origin (config/cors.php)           │
│ - Laravel Sanctum API Tokens (auth:sanctum)            │
│ - Tenant Isolation Middleware (EnsureTenantContext)    │
│ - Dynamic RBAC Middleware (CheckPermission)            │
│ - Database-Backed Cache, Sessions & Queues             │
└───────────────────────────┬────────────────────────────┘
                            │
            Encrypted TCP/IP│(Port 3306)
                            ▼
┌────────────────────────────────────────────────────────┐
│               Hosted MySQL 8.0+ Database               │
│     (AWS RDS, DigitalOcean Managed DB, Cloud MySQL)    │
│    Database: crm_production (utf8mb4_unicode_ci)      │
│    Dedicated Least-Privilege User (Restricted Host)    │
└────────────────────────────────────────────────────────┘
```

> [!IMPORTANT]
> **CRITICAL ARCHITECTURAL DISTINCTION**:
> The local MySQL 8 database accessed through MySQL Workbench on `127.0.0.1:3306` is a local workstation database. It is **not** reachable from external hosting environments or Vercel. Production **requires** a dedicated, hosted MySQL 8 database instance with network accessibility from your application server.

---

## 2. Verified Facts vs. Hosting Assumptions

To ensure deployment safety, distinguish verified codebase facts from hosting-provider assumptions:

### Verified Local Codebase Facts
- **Framework Version**: Laravel Framework `13.36.0` running on PHP `^8.3` (tested with PHP 8.5.0 CLI).
- **Package Integrity**: All platform requirements verified via `composer check-platform-reqs`.
- **Database Schema**: 13 migrations tested and fully compatible with MySQL 8.0.43 (InnoDB, `utf8mb4_unicode_ci`).
- **Route Prefix**: All API endpoints use the `/api/v1` prefix (41 routes registered).
- **Authentication**: Stateless Bearer tokens issued via Sanctum personal access tokens (`createToken('crm-auth-token')`). No cookies or session state required for authentication.
- **Automated Tests**: Security and tenant-isolation feature tests passing (5 tests, 24 assertions).

### Hosting-Provider Assumptions (Require Production Verification)
- **Hosted MySQL Connectivity**: Connection to remote MySQL 8 must be verified from the server command line.
- **Public / SSL Domain**: Domain name DNS resolution and HTTPS certificates must be provisioned on the host.
- **Vercel Frontend Domain**: The production frontend domain (e.g., `https://apex-crm.vercel.app`) is assigned upon Vercel deployment and must be configured in the backend `.env`.
- **Web Server Document Root**: Must be confirmed as pointing strictly to the `public/` directory.
- **Server Execution Privileges**: Shared hosting environments do not allow `sudo`, Nginx reconfiguration, or PHP-FPM service restarts.

---

## 3. Server Prerequisites & Platform Compatibility

### Supported PHP Versions
- **Minimum Requirement**: PHP `^8.3` (Verified with PHP 8.3 and PHP 8.5).
- **Composer**: Composer 2.5+ (Verified with Composer 2.8.12).

### Required PHP Extensions
Ensure the following extensions are active in your production PHP environment (`php -m`):
- `pdo` and `pdo_mysql` (Mandatory for MySQL 8 connection)
- `openssl` (Mandatory for cryptographic security)
- `mbstring` (Multibyte string handling)
- `tokenizer`, `xml`, `dom`, `ctype`, `json`, `curl`, `session`, `fileinfo`, `bcmath`

Verify on the server:
```bash
composer check-platform-reqs
```

---

## 4. Production Environment Configuration (`.env`)

### 4.1 Setup Procedure
On the production server, create the `.env` file from the sanitized template:
```bash
cp .env.example .env
```

### 4.2 Production `.env` Variable Checklist
Populate the file with production credentials (placeholders shown below):

```env
APP_NAME="Apex Dynamics CRM"
APP_ENV=production
APP_KEY=base64:EXISTING_KEY_DO_NOT_REGENERATE
APP_DEBUG=false
APP_URL=https://<backend-domain>
FRONTEND_URL=https://<your-frontend-app>.vercel.app

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

# Database Configuration (Hosted MySQL 8)
DB_CONNECTION=mysql
DB_HOST=your-hosted-mysql-endpoint.com
DB_PORT=3306
DB_DATABASE=crm_production
DB_USERNAME=crm_db_user
DB_PASSWORD=YOUR_STRONG_SECURE_PASSWORD

# Session, Cache, and Queue (Database-Backed)
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_PATH=/
SESSION_DOMAIN=null

# Sanctum Stateful Domains (Leave empty for stateless Bearer tokens)
SANCTUM_STATEFUL_DOMAINS=

QUEUE_CONNECTION=database
CACHE_STORE=database

# Logging (Use error or warning in production)
LOG_CHANNEL=stack
LOG_LEVEL=error
```

### 4.3 Preserving and Setting `APP_KEY`
- **Rule**: Never regenerate an `APP_KEY` for an existing database with encrypted payloads or active sessions. Regenerating will invalidate encrypted data.
- **Initial Deployment Only**: If deploying to a brand-new environment where `APP_KEY` is completely unset, generate it once:
  ```bash
  php artisan key:generate --show
  ```
  Copy the generated base64 string into `.env`.

---

## 5. MySQL 8 Database Setup & Least-Privilege Security

### 5.1 Database Creation
On your hosted MySQL 8 server, create the production database:
```sql
CREATE DATABASE crm_production 
  CHARACTER SET utf8mb4 
  COLLATE utf8mb4_unicode_ci;
```

### 5.2 Dedicated Least-Privilege Database User
Never use the `root` account in production. Create a dedicated user restricted to the CRM database and bound to the application server's specific IP or hostname (do not use wildcard `'%'` unless connecting through a managed cloud proxy with strict firewall rules):

```sql
-- Bind user to application server IP (e.g., 10.0.1.5 or localhost)
CREATE USER 'crm_db_user'@'10.0.1.5' IDENTIFIED BY 'YOUR_STRONG_SECURE_PASSWORD';

-- Grant only required application runtime and migration privileges (NO DROP by default)
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, LOCK TABLES 
  ON crm_production.* TO 'crm_db_user'@'10.0.1.5';

FLUSH PRIVILEGES;
```

> [!CAUTION]
> **LEAST-PRIVILEGE NOTICE**:
> Do not grant `DROP`, `GRANT OPTION`, or administrative privileges to the production database user by default. If a specific structural migration requires `DROP TABLE` or `DROP COLUMN`, grant elevated privileges temporarily during a scheduled maintenance window and revoke them immediately afterward.

### 5.3 Safe Database Import & Migration Verification
Never import a database backup into an existing production database without explicit verification:

1. **Verify Backup Integrity**:
   - Check file size (ensure it is not truncated or 0 bytes).
   - Verify the SQL header and syntax.
2. **Verify Destination Database**:
   - Confirm target database name (`SELECT DATABASE();`).
   - Check if destination already contains tables to prevent overwrite data loss.
3. **Approval-Gated Import**:
   - Test the backup import first on a local or staging database.
   - Obtain confirmation from the project owner before executing any import command against production:
     ```bash
     # Execute ONLY after verifying destination and obtaining approval:
     mysql -h your-hosted-mysql-endpoint.com -u crm_db_user -p crm_production < verified_backup.sql
     ```

---

## 6. Database Migrations (Approval-Gated Step)

Never execute migrations against a populated production database automatically. Follow this 4-step approval-gated workflow:

### Step 1: Inspect Pending Migrations
```bash
php artisan migrate:status
```
Review the list to identify exactly which migrations are pending.

### Step 2: Take a Verified Pre-Migration Backup
Before running migrations on any populated database, create a timestamped backup:
```bash
mysqldump -h your-hosted-mysql-endpoint.com -u crm_db_user -p crm_production > pre_migration_backup_$(date +%Y%m%d_%H%M%S).sql
```
Verify that the backup file is non-empty and readable.

### Step 3: Migration Safety Review
Inspect the migration source files to confirm they only add columns/tables and do not execute destructive table drops or data truncations.

### Step 4: Execute with Approval
Only after approval is confirmed, run non-destructive migrations:
```bash
php artisan migrate --force
```

> [!WARNING]
> **DESTRUCTIVE COMMAND PROHIBITION**:
> Never execute `php artisan migrate:fresh`, `php artisan db:wipe`, or `php artisan migrate:reset` in production. These commands delete all database tables and destroy customer data permanently.

---

## 7. Web Server & Document Root Configuration

### Critical Document Root Requirement
The web server's document root **MUST** point to the `public/` directory of the Laravel installation, **never** to the project root. Exposing the project root allows public HTTP access to `.env`, `composer.json`, and private source files.

---

### Option A: VPS / Cloud Hosting (Ubuntu, Nginx, PHP-FPM)
*(Requires root / `sudo` access)*

#### Nginx Configuration (`/etc/nginx/sites-available/crm-api.conf`)
```nginx
server {
    listen 80;
    listen [::]:80;
    server_name api.yourdomain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name api.yourdomain.com;

    root /var/www/crm/Backend/public;
    index index.php index.html;

    # SSL Certificates (e.g. Let's Encrypt Certbot)
    ssl_certificate /etc/letsencrypt/live/api.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.yourdomain.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    # Security Headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    # Deny access to hidden files (.env, .git)
    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

#### VPS-Only Permissions & Service Commands
*(Do NOT execute these on Shared Hosting)*
```bash
# Enable site & restart Nginx
sudo ln -s /etc/nginx/sites-available/crm-api.conf /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx

# Set directory ownership to web server user
sudo chown -R www-data:www-data /var/www/crm/Backend/storage /var/www/crm/Backend/bootstrap/cache
sudo chmod -R 775 /var/www/crm/Backend/storage /var/www/crm/Backend/bootstrap/cache

# Restart PHP-FPM after deployment
sudo systemctl restart php8.3-fpm
```

---

### Option B: Shared PHP Hosting (cPanel, Plesk, Hostinger)
*(No root or sudo access)*

In shared hosting environments, `sudo`, `chown`, and `systemctl` are not available. Use the hosting control panel:

1. **Document Root Setup**:
   - In cPanel / Plesk **Domains / Subdomains**, set the Document Root for `api.yourdomain.com` directly to `public_html/crm/Backend/public`.
   - Never set the document root to `public_html/crm/Backend` without pointing into `/public`.
2. **Directory Permissions**:
   - Open cPanel **File Manager**.
   - Ensure `Backend/storage` and `Backend/bootstrap/cache` directories are set to permission `755` (or `775` if the server runs suPHP/FastCGI).
   - Ensure files within storage are set to `644`.
3. **PHP Version**:
   - In cPanel **MultiPHP Manager** or **Select PHP Version**, ensure PHP `8.3` or higher is selected with `pdo_mysql`, `openssl`, and `mbstring` extensions enabled.
4. **Apache URL Rewriting**:
   - Verify that `Backend/public/.htaccess` is present and unchanged. Apache's `mod_rewrite` is enabled by default on standard cPanel installations.

---

## 8. CORS & Vercel Frontend Connectivity

### 8.1 Repository CORS Verification
Before applying environment variables, inspect `config/cors.php` in the repository to confirm how origins, methods, and headers are structured. 

The configuration in `config/cors.php` dynamically binds to `FRONTEND_URL`:
```php
'allowed_origins' => array_values(array_filter(array_unique(array_merge(
    array_map('trim', explode(',', env('FRONTEND_URL', 'http://localhost:5173'))),
    [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:3000',
    ]
)))),
```

### 8.2 Vercel Domain Configuration
When the Vercel frontend is deployed (e.g., `https://apex-crm.vercel.app`):
1. In the backend `.env`, set:
   ```env
   FRONTEND_URL=https://apex-crm.vercel.app
   ```
2. If multiple Vercel preview environments need access, supply a comma-separated list:
   ```env
   FRONTEND_URL=https://apex-crm.vercel.app,https://apex-crm-preview.vercel.app
   ```
3. In the Vercel dashboard for the frontend project, configure:
   ```env
   VITE_API_BASE_URL=https://<backend-domain>/api/v1
   ```

---

## 9. Sanctum Authentication Architecture

- **Authentication Method**: The CRM uses stateless **Laravel Sanctum Bearer Tokens** (`Authorization: Bearer <token>`).
- **No Cookies Required**: Do not enable Sanctum cookie-based SPA authentication, CSRF cookie exchanges (`/sanctum/csrf-cookie`), or stateful session cookies for the API.
- **Frontend Transmission**: The frontend client (`Frontend/src/lib/api/client.ts`) stores the token in `localStorage` upon login and attaches it as `Authorization: Bearer ${token}` for all API requests.
- **Configuration**: Keep `SANCTUM_STATEFUL_DOMAINS=` empty in the production `.env` to prevent unintentional cookie auth interception.

---

## 10. Composer & Optimization Commands

Deploy with dependencies optimized for production:

```bash
# 1. Install production dependencies without dev tools
composer install --no-dev --optimize-autoloader --no-interaction

# 2. Cache configuration
php artisan config:cache

# 3. Cache views
php artisan view:cache
```

### Route Caching Verification Note
> [!NOTE]
> **ROUTE CACHING VERIFICATION**:
> The route files (`routes/api.php` and `routes/web.php`) contain closure-based routes (`/health` and `/`). While Laravel 13 supports closure caching, closure serialization can behave differently depending on the hosting provider's PHP serialization settings.
> 
> **Procedure**:
> Test `php artisan route:cache` on the target server. If it succeeds, keep it active. If it throws a serialization error or warning on your specific hosting platform, clear the route cache:
> ```bash
> php artisan route:clear
> ```
> **Do not rewrite existing closure routes into controller methods solely to satisfy caching.**

### Clearing Caches (When modifying .env or troubleshooting)
```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
```

---

## 11. Health Check & Deployment Verification

After deployment, test the health check endpoint from an external terminal:

```bash
# 1. Test public health check
curl -i https://<backend-domain>/api/v1/health

# Expected Output:
# HTTP/2 200 OK
# Content-Type: application/json
# {"status":"healthy","timestamp":"2026-10-15T..."}

# 2. Test CORS preflight from Vercel domain
curl -i -X OPTIONS https://<backend-domain>/api/v1/health \
  -H "Origin: https://apex-crm.vercel.app" \
  -H "Access-Control-Request-Method: GET"

# Expected Output:
# HTTP/2 204 No Content
# Access-Control-Allow-Origin: https://apex-crm.vercel.app
# Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, HEAD

# 3. Test unauthenticated API rejection
curl -i https://<backend-domain>/api/v1/dashboard/metrics

# Expected Output:
# HTTP/2 401 Unauthorized
# {"message":"Unauthenticated."}
```

---

## 12. Rollback & Troubleshooting Procedures

### 12.1 Rollback Plan
1. **Application Code**:
   ```bash
   git checkout <PREVIOUS_STABLE_COMMIT_HASH>
   composer install --no-dev --optimize-autoloader
   php artisan config:cache
   ```
2. **Database Restoration** (if recovery is required and approved):
   ```bash
   mysql -h your-hosted-mysql-endpoint.com -u crm_db_user -p crm_production < pre_migration_backup_PREVIOUS.sql
   ```

### 12.2 Common Troubleshooting Matrix

| Issue | Probable Cause | Action |
| :--- | :--- | :--- |
| **500 Internal Server Error** | Storage directory permissions | Verify `storage/` and `bootstrap/cache/` are writable. Check `storage/logs/laravel.log`. |
| **CORS Error in Browser** | `FRONTEND_URL` mismatch | Verify that `FRONTEND_URL` in `.env` matches the exact Vercel protocol and domain. Clear config cache: `php artisan config:clear`. |
| **401 Unauthorized on all requests** | Stripped `Authorization` header | Ensure Nginx includes `fastcgi_params` or Apache has `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`. |
| **Database Connection Refused** | Firewall / Security Group | Verify cloud MySQL security group permits inbound traffic on port 3306 from the application server IP. |
| **Route Cache Error** | Closure serialization failure | Run `php artisan route:clear` and run uncached. Do not modify routes. |
