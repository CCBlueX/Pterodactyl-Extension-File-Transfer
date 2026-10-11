import { expect, test } from 'vitest';
import { describe, formatBytes, formatDuration, joinPath, parentPath } from './format';
import type { Transfer } from '../api';

test('formats sizes, durations and paths', () => {
    expect(formatBytes(512)).toBe('512 B');
    expect(formatBytes(100 * 1024 * 1024)).toBe('100.0 MiB');
    expect(formatDuration(42)).toBe('42s');
    expect(formatDuration(3725)).toBe('1h 2m');
    expect(joinPath('/', 'world')).toBe('/world');
    expect(joinPath('/a/', 'b')).toBe('/a/b');
    expect(parentPath('/a/b')).toBe('/a');
    expect(parentPath('/a')).toBe('/');
});

test('describes copies from the point of view of the open server', () => {
    const copy = {
        type: 'copy',
        source: { kind: 'server', label: 'Lobby', identifier: 'lobby123', path: '/' },
        target: { kind: 'server', label: 'Survival', identifier: 'surv1234', path: '/' },
    } as Transfer;

    expect(describe(copy, 'surv1234')).toBe('Copy from Lobby to this server');
    expect(describe(copy, 'lobby123')).toBe('Copy from this server to Survival');
});
