# GitHub Actions CI/CD Setup

This repository is configured with GitHub Actions for continuous integration and deployment.

## Workflows

### 1. CI Workflow (`.github/workflows/ci.yml`)
Runs on every push and pull request to `main` and `develop` branches.

**Jobs:**
- **Symfony Tests**: Runs tests with PHP 8.2 and 8.3, creates test database, runs migrations
- **Code Quality**: Checks PHP, Twig, YAML syntax and container configuration
- **Security Check**: Scans for known security vulnerabilities in dependencies

### 2. Deploy Workflow (`.github/workflows/deploy.yml`)
Deploys the application to production or staging environments.

**Triggers:**
- Automatic on push to `main` branch
- Manual via workflow dispatch

### 3. Docker Build and Push (`.github/workflows/docker.yml`)
Builds and pushes Docker images to GitHub Container Registry.

**Triggers:**
- Push to `main` or `develop` branches
- New version tags (v*)
- Pull requests

## Required GitHub Secrets

To use the deployment workflow, configure these secrets in your GitHub repository settings (Settings → Secrets and variables → Actions):

### Deployment Secrets
- `SSH_HOST`: Your server hostname or IP address
- `SSH_USERNAME`: SSH username for deployment
- `SSH_PRIVATE_KEY`: SSH private key for authentication
- `SSH_PORT`: SSH port (optional, defaults to 22)
- `DEPLOY_PATH`: Absolute path to your application on the server (e.g., `/var/www/symfony`)

### Optional Secrets for Production
- `APP_SECRET`: Symfony app secret
- `DATABASE_URL`: Production database connection string
- `JWT_PASSPHRASE`: JWT encryption passphrase
- `STRIPE_SECRET_KEY`: Stripe API secret key
- `BREVO_API_KEY`: Brevo (Sendinblue) API key

## Setting Up Secrets

1. Go to your GitHub repository
2. Navigate to **Settings** → **Secrets and variables** → **Actions**
3. Click **New repository secret**
4. Add each secret with its corresponding value

## Docker Image

The Docker image is automatically built and pushed to GitHub Container Registry:
- `ghcr.io/[username]/[repo]:latest` - Latest version from main branch
- `ghcr.io/[username]/[repo]:develop` - Latest version from develop branch
- `ghcr.io/[username]/[repo]:v1.0.0` - Tagged versions

## Running CI Locally

### PHP Syntax Check
```bash
find src -name "*.php" -print0 | xargs -0 -n1 php -l
```

### Twig Syntax Check
```bash
php bin/console lint:twig templates
```

### YAML Syntax Check
```bash
php bin/console lint:yaml config
```

### Container Check
```bash
php bin/console lint:container
```

## Manual Deployment

To manually trigger a deployment:
1. Go to **Actions** tab in your GitHub repository
2. Select **Deploy** workflow
3. Click **Run workflow**
4. Choose environment (production/staging)
5. Click **Run workflow**

## Environment Setup

For the CI/CD to work properly:

1. **Server Requirements:**
   - Git installed
   - PHP 8.2+ with required extensions
   - Composer installed
   - Database (PostgreSQL) running
   - Web server (Nginx/Apache) configured

2. **SSH Access:**
   - Generate SSH key pair
   - Add public key to server's `~/.ssh/authorized_keys`
   - Add private key to GitHub Secrets

3. **Application Setup on Server:**
   - Clone repository to deployment path
   - Configure `.env.prod.local` with production values
   - Set proper file permissions
   - Generate JWT keys if needed

## Troubleshooting

### Deployment Fails
- Check SSH connection: `ssh -i private_key username@host`
- Verify deployment path exists and is writable
- Check server logs: `/var/log/nginx/error.log` or `/var/log/apache2/error.log`

### Docker Build Fails
- Verify Dockerfile syntax
- Check if all required files are not in `.dockerignore`
- Review GitHub Actions logs for specific errors

### Tests Fail
- Check database connection in CI
- Verify all required PHP extensions are installed
- Review test output in GitHub Actions logs
