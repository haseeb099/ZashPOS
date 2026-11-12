import fs from 'node:fs';
import path from 'node:path';

const e2eRoot = process.cwd();

export function loadEnv(): void {
    const file = path.join(e2eRoot, '.env');

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

        if (
            (value.startsWith('"') && value.endsWith('"'))
            || (value.startsWith("'") && value.endsWith("'"))
        ) {
            value = value.slice(1, -1);
        }

        if (process.env[key] === undefined) {
            process.env[key] = value;
        }
    }
}

export function baseURL(): string {
    loadEnv();

    return process.env.E2E_BASE_URL || 'http://localhost:8000';
}

export function e2ePath(...parts: string[]): string {
    return path.join(e2eRoot, ...parts);
}
