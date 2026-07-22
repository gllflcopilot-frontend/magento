# Omnis Solutio Payment Gateway — Reusable Extension Workflow

Building this as a reusable extension that other people will `composer install` changes the setup meaningfully. The key shift is that your module needs to live in its own git repo from day one, and you develop it through Composer's `path` repository feature so you're not manually copying files between your test Magento install and your module repo.

**A note on where the repo actually lives:** this setup uses Mark Shust's docker-magento with the current default `compose.yaml`, where the whole Magento codebase (`app/code`, `vendor`, `generated`, everything) sits inside a named Docker volume (`appdata:/var/www/html`) — there is **no** bind-mounted `src/` folder on the host. That means the module's standalone repo has to live *inside the container's filesystem*, not on the host. A path repository pointing at a host path (e.g. `~/Sites/omnis-solutio-payment-gateway`) will never resolve, because the container has no visibility into the host filesystem beyond the few explicit mounts in `compose.yaml` (`~/.composer`, `~/.ssh`, and the named volumes).

## 1. Create the module's own repo inside the container

Shell into the container and create the repo directly inside the `appdata` volume, outside of `app/code` so it's clearly separated from the Magento install itself:

```bash
bin/cli bash
mkdir -p /var/www/html/modules/omnis-solutio-payment-gateway
cd /var/www/html/modules/omnis-solutio-payment-gateway
git init
```

Move your existing module code in (from `app/code/OmnisSolutio/PaymentGateway`) so this new folder contains *only* the module: `registration.php`, `etc/`, `Plugin/`, plus its own `composer.json`, `README.md`, `LICENSE`. Nothing from the rest of Magento.

```bash
cp -r /var/www/html/app/code/OmnisSolutio/PaymentGateway/* /var/www/html/modules/omnis-solutio-payment-gateway/
```

**Editing these files day-to-day:** since they live inside the named volume, a normal host-side editor pointed at a host folder won't see them. If you're using VS Code, install the **Dev Containers** extension and use "Attach to Running Container" (or "Reopen in Container") targeting this container — that lets you edit `/var/www/html/modules/omnis-solutio-payment-gateway` directly where it lives, with normal VS Code file browsing, git integration, etc.

## 2. Give the module its own composer.json

```json
{
    "name": "omnissolutio/payment-gateway",
    "description": "Omnis Solutio payment gateway checkout integration for Magento 2.",
    "type": "magento2-module",
    "license": "MIT",
    "require": {
        "php": "~8.3.0||~8.4.0",
        "magento/framework": "*"
    },
    "autoload": {
        "files": ["registration.php"],
        "psr-4": {
            "OmnisSolutio\\PaymentGateway\\": ""
        }
    },
    "version": "1.0.0"
}
```

Drop the explicit `version` key once you're publishing through Packagist — Packagist derives the version from git tags, and a hardcoded version in `composer.json` can drift out of sync and cause warnings.

**Important — case sensitivity:** the `"name"` field here (`omnissolutio/payment-gateway`, all lowercase) is what Composer matches against when you `require` it. It's easy to mix this up with your PHP namespace casing (`OmnisSolutio\PaymentGateway`) — but Composer package names are case-sensitive, and a mismatch here is the single most common cause of a "could not be found in any version" error even when the path repo itself is set up correctly.

## 3. Develop without copy-pasting — use a path repository

This is the part that actually solves your day-to-day workflow problem. Instead of repeatedly copying files from the module repo into `app/code/OmnisSolutio/PaymentGateway`, tell Composer to symlink it directly.

In your Magento install's root `composer.json` (at `/var/www/html/composer.json`), add:

```json
"repositories": [
    {
        "type": "path",
        "url": "/var/www/html/modules/omnis-solutio-payment-gateway",
        "options": { "symlink": true }
    }
]
```

Note the path is a **container-internal path** — since the module repo already lives inside the same container filesystem as the rest of Magento (per step 1), this just works; there's no host/container boundary to cross.

Then:

```bash
bin/composer require omnissolutio/payment-gateway:@dev
```

Composer creates a symlink from `vendor/omnissolutio/payment-gateway` into your repo folder. Now any edit you make in `/var/www/html/modules/omnis-solutio-payment-gateway` is *instantly* live in the Magento install — no manual copying. Just remember to enable it and run setup:upgrade after any `etc/` or `registration.php` change:

```bash
bin/magento module:enable OmnisSolutio_PaymentGateway
bin/magento setup:upgrade
```

**If `composer require` says the package "could not be found in any version,"** check these three things in order — this covers basically every cause:

```bash
# 1. Is the repositories block actually in the root composer.json Composer is reading?
bin/cli cat composer.json | grep -A 8 '"repositories"'

# 2. Does the path actually exist inside the container?
bin/cli ls -la /var/www/html/modules/omnis-solutio-payment-gateway

# 3. Does the module's own composer.json declare the exact (lowercase) name you're requiring?
bin/cli cat /var/www/html/modules/omnis-solutio-payment-gateway/composer.json
```

## 4. Version control and tagging

Your SSH key is already mounted into the container (`~/.ssh/id_rsa` and `~/.ssh/known_hosts` in `compose.yaml`), so git push/pull over SSH works from inside the container without extra setup. Commit and tag using semver — Composer/Packagist read tags as released versions:

```bash
bin/cli bash
cd /var/www/html/modules/omnis-solutio-payment-gateway
git add .
git commit -m "Initial release"
git remote add origin https://github.com/omnissolutio/payment-gateway.git
git push -u origin main
git tag v1.0.0
git push origin v1.0.0
```

Going forward, every release is just a new tag (`v1.0.1`, `v1.1.0`, etc.) following semver: patch for fixes, minor for backward-compatible features, major for breaking changes.

## 5. Publish to Packagist so people get plain `composer require`

Go to [packagist.org](https://packagist.org), sign in with GitHub, and submit your repo URL. Packagist validates your `composer.json` and starts tracking your tags as installable versions. Set up the GitHub webhook it offers (one click) so new tags auto-publish without you manually pinging Packagist each time.

Once that's live, anyone can install it with just:

```bash
composer require omnissolutio/payment-gateway
bin/magento module:enable OmnisSolutio_PaymentGateway
bin/magento setup:upgrade
```

No VCS repository config needed on their end, and no dependency on your container setup at all — the path repository was purely a local development convenience. Once installed from Packagist, other developers pull the tagged release over the network like any other Composer package.

## 6. A few things worth setting up before your first real release

A `README.md` with installation instructions and what the plugin actually does — this is what people read on Packagist and GitHub before installing anything. A `LICENSE` file (MIT is the common default for open Magento extensions). And if you want to look credible to other developers, run your code through the [Magento Coding Standard](https://github.com/magento/magento-coding-standard) (`phpcs --standard=Magento2`) before tagging a release — a lot of store owners check this before trusting a third-party module.

One thing worth deciding now rather than later: do you want this purely as a free/open package on Packagist, or do you eventually want it listed on the official Magento Marketplace (which has its own extension quality review and packaging requirements, separate from Packagist)? That affects how strict you need to be about coding standards and `composer.json` metadata from the start.

## Appendix — if you ever move to a bind-mount-based docker-magento setup

Some docker-magento configurations (older versions, or ones using a `docker-compose.dev.yml` override) bind-mount specific host folders like `./src/app/code` into the container instead of keeping everything in a named volume. If you migrate to that kind of setup later, you'd instead:

1. Keep the module repo on your host filesystem (e.g. `~/Sites/omnis-solutio-payment-gateway`).
2. Add an explicit bind mount for it in your compose override:
   ```yaml
   services:
     app:
       volumes:
         - ~/Sites/omnis-solutio-payment-gateway:/var/www/html/modules/omnis-solutio-payment-gateway:cached
   ```
3. Recreate the container (`docker compose up -d --force-recreate app`) and point the path repository at the same container-side path as above.

Everything else in this workflow (composer.json, tagging, Packagist) stays identical either way — only *where the files physically live* changes.