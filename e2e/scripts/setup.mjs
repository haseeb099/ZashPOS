import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const e2eRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const projectRoot = path.dirname(e2eRoot);
const envFile = path.join(projectRoot, '.env.e2e');
const sqlitePath = path.join(projectRoot, 'database', 'e2e.sqlite');

function loadDotEnv(file) {
    if (!fs.existsSync(file)) {
        return;
    }

    for (const line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
        const trimmed = line.trim();

        if (trimmed === '' || trimmed.startsWith('#')) {
            continue;
        }

        const separator = trimmed.indexOf('=');

        if (separator < 1) {
            continue;
        }

        const key = trimmed.slice(0, separator).trim();
        let value = trimmed.slice(separator + 1).trim();

        if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
            value = value.slice(1, -1);
        }

        if (process.env[key] === undefined) {
            process.env[key] = value;
        }
    }
}

function requireEnv(name) {
    const value = process.env[name];

    if (!value) {
        throw new Error(`Missing ${name}. Copy e2e/.env.example to e2e/.env and fill it in. Do not commit e2e/.env.`);
    }

    return value;
}

function upsertEnv(contents, key, value) {
    const line = `${key}=${value}`;
    const pattern = new RegExp(`^${key}=.*$`, 'm');

    if (pattern.test(contents)) {
        return contents.replace(pattern, line);
    }

    return `${contents.trim()}\n${line}\n`;
}

function ensureLaravelEnv() {
    if (!fs.existsSync(envFile)) {
        const source = path.join(projectRoot, '.env');

        if (!fs.existsSync(source)) {
            throw new Error('Create .env.e2e (not committed) or provide a root .env to copy from.');
        }

        fs.copyFileSync(source, envFile);
    }

    let contents = fs.readFileSync(envFile, 'utf8');
    const baseUrl = process.env.E2E_BASE_URL || 'http://127.0.0.1:8004';
    let hostPort = '127.0.0.1:8004';

    try {
        hostPort = new URL(baseUrl).host;
    } catch {
        // Keep the Phase 4 default host:port.
    }

    contents = upsertEnv(contents, 'DB_CONNECTION', 'sqlite');
    contents = upsertEnv(contents, 'DB_DATABASE', 'database/e2e.sqlite');
    contents = upsertEnv(contents, 'APP_URL', baseUrl);
    contents = upsertEnv(contents, 'SESSION_COOKIE', process.env.E2E_SESSION_COOKIE || 'zashpos_e2e_p4');
    contents = upsertEnv(contents, 'CACHE_PREFIX', process.env.E2E_CACHE_PREFIX || 'zashpos_e2e_p4');
    contents = upsertEnv(contents, 'MAIL_MAILER', 'log');
    contents = upsertEnv(contents, 'SANCTUM_STATEFUL_DOMAINS', `${hostPort},localhost,127.0.0.1`);
    // Product/category generation must not require a live Reverb process.
    contents = upsertEnv(contents, 'BROADCAST_DRIVER', 'log');
    fs.writeFileSync(envFile, contents);
}

function artisan(args) {
    const result = spawnSync('php', ['artisan', '--env=e2e', ...args], {
        cwd: projectRoot,
        encoding: 'utf8',
        env: process.env,
    });
    const output = `${result.stdout || ''}\n${result.stderr || ''}`;

    if (output.trim() !== '') {
        process.stdout.write(output.endsWith('\n') ? output : `${output}\n`);
    }

    return { status: result.status ?? 1, output };
}

function artisanOrFail(args) {
    const result = artisan(args);

    if (result.status !== 0) {
        throw new Error(`php artisan --env=e2e ${args.join(' ')} failed.`);
    }
}

loadDotEnv(path.join(e2eRoot, '.env'));

const adminUser = requireEnv('E2E_ADMIN_USER');
const adminPassword = requireEnv('E2E_ADMIN_PASSWORD');
requireEnv('E2E_WAITER_USER');
requireEnv('E2E_WAITER_PASSWORD');
requireEnv('E2E_CASHIER_USER');
requireEnv('E2E_CASHIER_PASSWORD');
requireEnv('E2E_CHEF_USER');
requireEnv('E2E_CHEF_PASSWORD');

ensureLaravelEnv();

for (const suffix of ['', '-journal', '-wal', '-shm']) {
    const file = `${sqlitePath}${suffix}`;

    if (fs.existsSync(file)) {
        fs.unlinkSync(file);
    }
}

fs.writeFileSync(sqlitePath, '');

artisanOrFail([
    'ns:setup',
    '--store_name=E2E Zash',
    `--admin_username=${adminUser}`,
    '--admin_email=e2e-admin@example.com',
    `--admin_password=${adminPassword}`,
    '--language=en',
]);

artisanOrFail(['modules:enable', 'ZashDine']);

const migrate = artisan(['modules:migrate', 'ZashDine', '--force']);

if (migrate.status !== 0) {
    if (!migrate.output.includes('option does not exist')) {
        throw new Error('php artisan modules:migrate ZashDine --force failed.');
    }

    process.stdout.write('modules:migrate has no --force option; retrying without it.\n');
    artisanOrFail(['modules:migrate', 'ZashDine']);
}

artisanOrFail(['db:seed', '--class=Database\\Seeders\\DefaultUnitGroupSeeder', '--no-interaction', '--force']);
artisanOrFail(['ns:categories:generate', '--count=4']);
artisanOrFail(['ns:products:generate', '--count=30']);

const provision = path.join(e2eRoot, 'scripts', 'provision.php').replace(/\\/g, '/');
const provisioned = artisan(['tinker', '--execute', `require '${provision}';`]);

if (provisioned.status !== 0 || !provisioned.output.includes('E2E waiter, cashier and chef users are ready.')) {
    throw new Error('Unable to create the Playwright waiter, cashier and chef users.');
}
artisanOrFail(['cache:clear']);
