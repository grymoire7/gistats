# Deploy Strategy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up a two-job GitHub Actions pipeline that tests every push/PR and deploys `main` to DreamHost shared hosting via SFTP, with a `config.local.php` override mechanism for production-only settings.

**Architecture:** A `test` job (PHP lint + PHPUnit) gates a `deploy` job (build assets, stage a filtered `dist/` copy, SFTP it to DreamHost) via `needs: test`. Production-only config (`base_url`, `db_path`) is supplied by a gitignored `config.local.php` on the server that `config.php` merges over its defaults — the file is never deployed and survives redeploys because the SFTP action never deletes remote files.

**Tech Stack:** GitHub Actions, `shivammathur/setup-php`, `actions/setup-node`, `wlixcc/SFTP-Deploy-Action@v1.2.4`, PHP 8+, PHPUnit, Tailwind CSS v4, `rsync`.

## Global Constraints

- TDD red-green-refactor for all development (write failing test → minimal implementation → pass) — from project `CLAUDE.md`. Applies fully to Task 1 (`config.php`); Tasks 2–3 (CI YAML) have no unit-test harness, so "test" there means a concrete local/remote verification command, specified in each step.
- Conventional commit format for all commits — from user's global `CLAUDE.md`.
- Deploy target: `/home/ccshell/magicbydesign.com/gistats/` on DreamHost, reached via `wlixcc/SFTP-Deploy-Action@v1.2.4` with `delete_remote_files: false`.
- `test` job triggers on every push (all branches) and every pull request. `deploy` job triggers only on push to `main` or `workflow_dispatch`, and only `needs: test`.
- rsync excludes (from spec, **plus additions** — see deviations below): `tests/`, `server.php`, `docs/`, `tmp/`, `css/input.css`, `*.md`, `phpunit.xml`, `composer.json`, `composer.lock`, `package*.json`, `mise.toml`, `pitchfork.toml` (deviations), `node_modules/` (deviation), `dist/` itself (required so the rsync doesn't recurse into its own staging output), and a blanket `--exclude='.*'` covering `.git/`, `.claude/`, `.rodney/`, `.github/` (deviation, replaces the spec's per-dotfile list) — guarded by `--include='.htaccess'` placed *before* it, since `.htaccess` is a tracked dotfile required for Apache's mod_rewrite routing and must not be swept up by the blanket exclude. Note `seed.php` is deliberately **not** excluded (see deviation 4 below) — but because it's a bare `.php` file, not a dotfile, it isn't touched by the `--exclude='.*'` rule either way; its own `--exclude seed.php` line was simply removed. `config.local.php` is NOT covered by `--exclude='.*'` either (it doesn't start with a dot) — it's kept off the server by a different mechanism entirely: it's gitignored, so `actions/checkout` never produces it in the CI workspace, and there's nothing to stage or exclude.
- The SFTP action's `local_path: './dist/*'` is a shell glob, and per `sftp`/glob semantics globs don't match dotfiles — so `.htaccess` (preserved into `dist/` by the `--include` above) would silently never be uploaded by the main "Deploy via SFTP" step. A second step, "Deploy .htaccess", uploads it by literal path (`./dist/.htaccess`, no wildcard) right after the main SFTP step — see Task 3, Step 1.
- Secrets `DREAMHOST_HOST`, `DREAMHOST_USERNAME`, `DREAMHOST_PASSWORD` are consumed by name in the workflow but **do not currently exist** in the `grymoire7/gistats` repo (confirmed via `gh secret list` — empty). Creating them requires real DreamHost credentials only the user has; this plan documents the exact command but does not run it.

### Deviations from spec's rsync exclude list

1. **`node_modules/` added.** Large, dev-only, only needed to build CSS which already happens in CI before the rsync. Publishing it to shared hosting serves no purpose.
2. **`mise.toml` and `pitchfork.toml` added.** Both are tracked dev-tooling configs (task runner, local daemon definitions) with no runtime purpose on DreamHost. Apache's `.htaccess` serves any file that exists on disk directly, bypassing the router — so without this exclusion both would be publicly downloadable at e.g. `magicbydesign.com/gistats/mise.toml`, disclosing internal dev-tooling details for no benefit. (`LICENSE`, also tracked and unmentioned by the spec, is deliberately left unexcluded — shipping a license file to production is normal.)
3. **Per-dotfile excludes (`.git/`, `.claude/`, `.rodney/`, `.github/`) collapsed into one blanket `--exclude='.*'`.** Simpler than enumerating every dot-directory, and covers any future dotfile/dot-directory without editing the workflow again. The only tracked dotfiles in the repo are `.gitignore` and `.htaccess` (verified via `git ls-files | grep -E '(^|/)\.[^/]+$'`) — `.gitignore` being swept up is harmless, but `.htaccess` is load-bearing (Apache mod_rewrite routes every request through `index.php` via it — see README's "Deploying with Apache" section). So `--include='.htaccess'` is placed *before* `--exclude='.*'` to preserve it (rsync filter rules are first-match-wins).
4. **`seed.php` is NOT excluded**, despite the original design spec listing it among the rsync excludes. `seed.php` self-guards against web access (`if (php_sapi_name() !== 'cli') { http_response_code(403); exit('CLI only'); }`), so it's safe to publish. More importantly, README's "Deploying with GitHub Actions" one-time setup step runs `php seed.php` directly on the server — excluding it would leave that step broken with the file missing. So the `--exclude seed.php` line is deliberately omitted.
5. **`config.local.php` is kept off the server by gitignore, not by the rsync exclude list.** It doesn't start with a dot, so `--exclude='.*'` never touches it — but it's gitignored, so `actions/checkout` never produces it in the CI workspace in the first place, meaning there's nothing for the rsync to stage or need to exclude.
6. **A second "Deploy .htaccess" SFTP step was added** (see Task 3, Step 1). The main "Deploy via SFTP" step's `local_path: './dist/*'` is a wildcard, and `wlixcc/SFTP-Deploy-Action` resolves it via `sftp`'s `put -r`, which follows standard shell/glob semantics that skip dotfiles — so `.htaccess` would silently never upload despite surviving the rsync stage. The second step uploads it by literal path (`./dist/.htaccess`, no wildcard), which isn't subject to that dotfile-skipping rule.

If you disagree with either, adjust the `rsync` step in Task 3 — nothing else in the plan depends on these choices.

---

### Prerequisite (manual, before Task 3's deploy job can run end-to-end)

Not a task — this needs real credentials the implementer must supply:

```bash
gh secret set DREAMHOST_HOST --repo grymoire7/gistats
gh secret set DREAMHOST_USERNAME --repo grymoire7/gistats
gh secret set DREAMHOST_PASSWORD --repo grymoire7/gistats
```

Each command prompts for the secret value on stdin. Verify with `gh secret list --repo grymoire7/gistats`.

---

### Task 1: `config.local.php` merge support

**Files:**
- Modify: `config.php`
- Modify: `.gitignore`
- Test: `tests/ConfigTest.php`

**Interfaces:**
- Produces: `config.php` returns an array that is `$defaults` (`db_path`, `timezone`, `base_url`) merged with the contents of `config.local.php` when that file exists at `__DIR__ . '/config.local.php'`, else just `$defaults`.

- [ ] **Step 1: Write the failing test**

Append to `tests/ConfigTest.php` (inside the `ConfigTest` class, after the existing two test methods):

```php
    public function testMergesConfigLocalPhpOverDefaultsWhenPresent(): void
    {
        $localPath = __DIR__ . '/../config.local.php';
        file_put_contents($localPath, "<?php return ['base_url' => '/gistats'];\n");
        try {
            $config = require __DIR__ . '/../config.php';
            $this->assertEquals('/gistats', $config['base_url']);
            $this->assertEquals('America/Chicago', $config['timezone']);
        } finally {
            unlink($localPath);
        }
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/ConfigTest.php`
Expected: FAIL on `testMergesConfigLocalPhpOverDefaultsWhenPresent` — `base_url` is still `''` because `config.php` doesn't yet look for `config.local.php`.

- [ ] **Step 3: Implement the merge**

Replace the full contents of `config.php` with:

```php
<?php
$defaults = [
    'db_path'  => getenv('GISTATS_DB_PATH') ?: __DIR__ . '/database.sqlite',
    'timezone' => 'America/Chicago',
    'base_url' => '',
];
if (file_exists(__DIR__ . '/config.local.php')) {
    return array_merge($defaults, require __DIR__ . '/config.local.php');
}
return $defaults;
```

- [ ] **Step 4: Run all config tests to verify they pass**

Run: `vendor/bin/phpunit tests/ConfigTest.php`
Expected: PASS (3 tests: the 2 pre-existing plus the new one).

- [ ] **Step 5: Ignore `config.local.php` and verify it's actually ignored**

Add this to `.gitignore` (near the "Local database" section):

```
# Production config override (never committed)
config.local.php
```

Verify:

```bash
touch config.local.php && git status --porcelain | grep config.local.php
```

Expected: no output (file does not show up as untracked). Then:

```bash
rm config.local.php
```

- [ ] **Step 6: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: PASS, no regressions.

- [ ] **Step 7: Commit**

```bash
git add config.php .gitignore tests/ConfigTest.php
git commit -m "feat: merge config.local.php overrides into config.php"
```

---

### Task 2: CI workflow — test job

**Files:**
- Create: `.github/workflows/deploy.yml`

**Interfaces:**
- Produces: a `test` job named `test` in `.github/workflows/deploy.yml` that Task 3's `deploy` job will reference via `needs: test`.

- [ ] **Step 1: Create the workflow file with only the test job**

Create `.github/workflows/deploy.yml`:

```yaml
name: Deploy

on:
  push:
  pull_request:
  workflow_dispatch:

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'

      - name: PHP syntax lint
        run: git ls-files '*.php' | xargs -n1 -P4 php -l

      - name: Install PHP dependencies
        run: composer install --prefer-dist --no-progress

      - name: Run PHPUnit
        run: vendor/bin/phpunit
```

- [ ] **Step 2: Validate YAML syntax locally**

Run: `ruby -ryaml -e "YAML.load_file('.github/workflows/deploy.yml'); puts 'valid'"`
Expected: `valid`

- [ ] **Step 3: Sanity-check the same commands pass locally**

Run:

```bash
git ls-files '*.php' | xargs -n1 -P4 php -l
composer install --prefer-dist --no-progress
vendor/bin/phpunit
```

Expected: all three succeed (this is exactly what the `test` job will run).

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/deploy.yml
git commit -m "ci: add PHP lint and PHPUnit test job"
```

- [ ] **Step 5: Push on a branch and confirm the workflow actually runs on GitHub**

```bash
git push -u origin HEAD
gh run list --branch "$(git branch --show-current)" --limit 1
```

Expected: a run for workflow "Deploy" with the `test` job, status `completed` / conclusion `success`. Use `gh run watch` if it's still in progress.

---

### Task 3: CI workflow — deploy job

**Files:**
- Modify: `.github/workflows/deploy.yml` (append `deploy` job)

**Interfaces:**
- Consumes: `test` job from Task 2 (via `needs: test`); `DREAMHOST_HOST`, `DREAMHOST_USERNAME`, `DREAMHOST_PASSWORD` secrets (see Prerequisite above).
- Produces: a `deploy` job that stages a filtered `dist/` copy of the repo and SFTPs it to `/home/ccshell/magicbydesign.com/gistats/`.

- [ ] **Step 1: Append the deploy job**

Add to `.github/workflows/deploy.yml`, after the `test:` job (same `jobs:` map, same indentation level):

```yaml
  deploy:
    needs: test
    if: (github.event_name == 'push' && github.ref == 'refs/heads/main') || github.event_name == 'workflow_dispatch'
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'

      - name: Install production PHP dependencies
        run: composer install --no-dev --optimize-autoloader

      - uses: actions/setup-node@v4
        with:
          node-version: 'lts/*'

      - name: Install and build CSS
        run: |
          npm ci
          npm run build:css

      - name: Stage production files
        run: |
          mkdir -p dist
          rsync -a ./ dist/ \
            --include '.htaccess' \
            --exclude '.*' \
            --exclude tests/ \
            --exclude server.php \
            --exclude docs/ \
            --exclude tmp/ \
            --exclude css/input.css \
            --exclude '*.md' \
            --exclude phpunit.xml \
            --exclude composer.json \
            --exclude composer.lock \
            --exclude 'package*.json' \
            --exclude mise.toml \
            --exclude pitchfork.toml \
            --exclude node_modules/ \
            --exclude dist/

      - name: Deploy via SFTP
        uses: wlixcc/SFTP-Deploy-Action@v1.2.4
        with:
          username: ${{ secrets.DREAMHOST_USERNAME }}
          server: ${{ secrets.DREAMHOST_HOST }}
          password: ${{ secrets.DREAMHOST_PASSWORD }}
          local_path: './dist/*'
          remote_path: '/home/ccshell/magicbydesign.com/gistats/'
          delete_remote_files: false

      - name: Deploy .htaccess
        uses: wlixcc/SFTP-Deploy-Action@v1.2.4
        with:
          username: ${{ secrets.DREAMHOST_USERNAME }}
          server: ${{ secrets.DREAMHOST_HOST }}
          password: ${{ secrets.DREAMHOST_PASSWORD }}
          local_path: './dist/.htaccess'
          remote_path: '/home/ccshell/magicbydesign.com/gistats/'
          delete_remote_files: false
```

- [ ] **Step 2: Validate YAML syntax locally**

Run: `ruby -ryaml -e "YAML.load_file('.github/workflows/deploy.yml'); puts 'valid'"`
Expected: `valid`

- [ ] **Step 3: Dry-run the rsync staging step locally, without touching production**

```bash
mkdir -p dist
rsync -a ./ dist/ \
  --include '.htaccess' \
  --exclude '.*' \
  --exclude tests/ \
  --exclude server.php \
  --exclude docs/ \
  --exclude tmp/ \
  --exclude css/input.css \
  --exclude '*.md' \
  --exclude phpunit.xml \
  --exclude composer.json \
  --exclude composer.lock \
  --exclude 'package*.json' \
  --exclude mise.toml \
  --exclude pitchfork.toml \
  --exclude node_modules/ \
  --exclude dist/
ls dist/
ls -d dist/.htaccess dist/seed.php
ls dist/tests dist/composer.json dist/node_modules dist/.git dist/.github dist/mise.toml dist/pitchfork.toml 2>&1 | true
rm -rf dist
```

Expected: `ls dist/` shows `index.php`, `config.php`, `router.php`, `seed.php` is **present** (deliberately shipped — see Global Constraints deviation 4), `server.php` is **absent**, `lib/`, `views/`, `css/` (with `compiled.css` present since it was built in an earlier task-2/local run — if missing, run `npm run build:css` first), `images/`, `vendor/`. `ls -d dist/.htaccess dist/seed.php` succeeds (proving both the include-before-blanket-exclude guard and the seed.php non-exclusion work). The final `ls` for excluded paths should report "No such file or directory" for all of them, including `dist/.git`, `dist/.github`, `dist/mise.toml`, and `dist/pitchfork.toml`.

- [ ] **Step 4: Confirm the deploy secrets exist (prerequisite check)**

Run: `gh secret list --repo grymoire7/gistats`
Expected: `DREAMHOST_HOST`, `DREAMHOST_USERNAME`, `DREAMHOST_PASSWORD` all listed. If any are missing, run the `gh secret set` commands from the Prerequisite section before proceeding — the deploy job will fail authentication otherwise.

- [ ] **Step 5: Commit**

```bash
git add .github/workflows/deploy.yml
git commit -m "ci: add SFTP deploy job to DreamHost on main pushes"
```

- [ ] **Step 6: Push and open a PR (do not merge yet)**

```bash
git push -u origin HEAD
gh pr create --title "ci: add DreamHost deploy pipeline" --body "Adds test+deploy GitHub Actions workflow and config.local.php override support. See docs/specs/2026-05-28-deploy-strategy-design.md and docs/plans/2026-07-21-deploy-strategy.md."
```

Merging this PR to `main` is the point where the `deploy` job will actually run and SFTP files to the live production site for the first time — confirm the secrets prerequisite (Step 4) is satisfied, then get explicit go-ahead before merging.

---

### Task 4: README documentation

**Files:**
- Modify: `README.md`

**Interfaces:**
- None (documentation only).

- [ ] **Step 1: Add a "Deploying with GitHub Actions" section**

Insert this new section into `README.md` immediately after the existing "### Deploying with Apache" subsection (which stays, since it documents the underlying Apache/`.htaccess` behavior the pipeline relies on):

```markdown
### Deploying with GitHub Actions

Pushing to `main` runs a two-job pipeline (`.github/workflows/deploy.yml`):

- **`test`** — runs on every push and pull request: PHP syntax lint, then `vendor/bin/phpunit`.
- **`deploy`** — runs only on push to `main` (or manual `workflow_dispatch`), and only if `test` passes. Builds production dependencies and compiled CSS, stages a filtered copy of the repo into `dist/`, and SFTPs it to DreamHost.

**Required GitHub secrets** (repo Settings → Secrets and variables → Actions):

| Secret | Value |
| --- | --- |
| `DREAMHOST_HOST` | DreamHost SFTP host |
| `DREAMHOST_USERNAME` | DreamHost SFTP username |
| `DREAMHOST_PASSWORD` | DreamHost SFTP password |

**One-time server setup**, via SSH:

```bash
mkdir -p ~/data/gitstats
```

After the first deploy creates the `gistats/` web directory, place `~/magicbydesign.com/gistats/config.local.php` on the server:

```php
<?php return [
    'base_url' => '/gistats',
    'db_path'  => '/home/ccshell/data/gitstats/database.sqlite',
];
```

Then seed the production database:

```bash
cd ~/magicbydesign.com/gistats
php seed.php
```

`config.local.php` is gitignored and never deployed (the SFTP action never deletes remote files), so it survives every subsequent deploy.
```

- [ ] **Step 2: Proofread against the actual workflow file**

Re-read `.github/workflows/deploy.yml` and confirm the README's job descriptions, trigger conditions, and secret names match exactly what Tasks 2–3 produced.

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs: document GitHub Actions deploy pipeline"
```

---

## Manual steps after merge (not automatable from this repo)

These require real DreamHost SSH access and cannot be scripted by an implementer without credentials — perform them once, after Task 3's PR is merged and the first `deploy` run has completed successfully:

1. SSH into DreamHost and create the data directory: `mkdir -p ~/data/gitstats`
2. Confirm the first deploy created `~/magicbydesign.com/gistats/`.
3. Create `~/magicbydesign.com/gistats/config.local.php` with the contents shown in Task 4, Step 1.
4. `cd ~/magicbydesign.com/gistats && php seed.php` to create and seed the production database.
5. Visit `https://magicbydesign.com/gistats/` and confirm the app loads and login works.
