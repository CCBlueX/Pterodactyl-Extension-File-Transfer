import type { Transfer } from '../api';

export function formatBytes(bytes: number): string {
    const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${unit === 0 ? value : value.toFixed(1)} ${units[unit]}`;
}

export function formatDuration(seconds: number): string {
    if (seconds < 60) return `${seconds}s`;
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ${seconds % 60}s`;

    return `${Math.floor(seconds / 3600)}h ${Math.floor((seconds % 3600) / 60)}m`;
}

export function joinPath(folder: string, name: string): string {
    return `${folder.replace(/\/+$/, '')}/${name}`;
}

export function parentPath(path: string): string {
    const parent = path.replace(/\/+$/, '').split('/').slice(0, -1).join('/');

    return parent === '' ? '/' : parent;
}

export function plural(count: number, word: string): string {
    return `${count.toLocaleString()} ${word}${count === 1 ? '' : 's'}`;
}

const TYPES = { import: 'Import', export: 'Export', copy: 'Copy' } as const;

/** "Import from sftp://user@host:22/path" style titles, seen from the current server. */
export function describe(transfer: Transfer, current: string): string {
    const side = (part: Transfer['source']) => (part.kind === 'server' && part.identifier === current ? 'this server' : part.label);

    return `${TYPES[transfer.type]} from ${side(transfer.source)} to ${side(transfer.target)}`;
}

export const STATUS_LABELS: Record<Transfer['status'], string> = {
    queued: 'Waiting',
    running: 'Running',
    cancelling: 'Cancelling',
    done: 'Done',
    failed: 'Failed',
    cancelled: 'Cancelled',
};

export const STEP_LABELS: Record<Transfer['step'], string> = {
    wipe: 'Wiping the target folder',
    scan: 'Looking for files',
    transfer: 'Moving files',
    cleanup: 'Cleaning up',
    done: 'Finishing',
};
