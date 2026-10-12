import { afterEach, expect, test, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { createExtensionTestHost, createTestServer } from '@pterodactyl/sdk/testing';
import * as api from '../api';
import TransferScreen, { readSearch } from './TransferScreen';

vi.mock('../api', async (importOriginal) => ({
    ...(await importOriginal<typeof import('../api')>()),
    getOverview: vi.fn(),
    testConnection: vi.fn(),
    listRemote: vi.fn(),
    startImport: vi.fn(),
    cancelTransfer: vi.fn(),
    searchServers: vi.fn(),
}));

let host: ReturnType<typeof createExtensionTestHost> | undefined;

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
    vi.clearAllMocks();
});

const transfer = (changes: Partial<api.Transfer> = {}): api.Transfer => ({
    id: 3,
    type: 'import',
    status: 'running',
    step: 'transfer',
    source: { kind: 'remote', label: 'sftp://foo@files.example.com:22', identifier: null, path: '/srv/old' },
    target: { kind: 'server', label: 'Survival', identifier: 'abcd1234', path: '/' },
    selection: [],
    options: { overwrite: false, wipe: false, archive: false },
    archive: null,
    files: { total: 40, ok: 9, failed: 1, skipped: 0 },
    bytes: { total: 4096, done: 1024 },
    current_path: 'world/region/r.0.0.mca',
    error: null,
    user: 'dev',
    elapsed: 75,
    created_at: '2026-10-11T12:00:00+00:00',
    finished_at: null,
    ...changes,
});

const overview = (changes: Partial<api.Overview> = {}): api.Overview => ({
    features: { import: true, export: true, copy: true },
    protocols: [
        { value: 'sftp', label: 'SFTP' },
        { value: 'ftp', label: 'FTP' },
    ],
    limits: { remote: 100 * 1024 * 1024, copy: 50 * 1024 * 1024 },
    can: { wipe: true, archive: true, read: true, write: true },
    active: null,
    history: [],
    ...changes,
});

function renderScreen(data: api.Overview, search: Record<string, unknown> = {}) {
    vi.mocked(api.getOverview).mockResolvedValue(data);
    const server = createTestServer({ owner: true, identifier: 'abcd1234', name: 'Survival' });
    host = createExtensionTestHost({ extensionId: 'file-transfer', prefix: 'xfer', server });
    render(<TransferScreen data={{ pathname: '', search, params: { id: 'abcd1234' } }} />, { wrapper: host.Wrapper });
}

test('shows the running transfer with live counts and lets it be cancelled', async () => {
    vi.mocked(api.cancelTransfer).mockResolvedValue(transfer({ status: 'cancelling' }));
    renderScreen(overview({ active: transfer(), history: [transfer({ id: 2, status: 'done', elapsed: 30, files: { total: 5, ok: 4, failed: 1, skipped: 0 } })] }));

    expect(await screen.findAllByText('Import from sftp://foo@files.example.com:22 to this server')).toHaveLength(2);
    expect(screen.getByText('10 of 40 files, 1.0 KiB of 4.0 KiB.')).toBeTruthy();
    expect(screen.getByText('world/region/r.0.0.mca')).toBeTruthy();
    expect(screen.getByText('1m 15s')).toBeTruthy();
    expect(screen.getByText('Done with errors')).toBeTruthy();
    expect(screen.getByText('A new transfer can start once the running one is done.')).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Cancel transfer' }));
    await waitFor(() => expect(api.cancelTransfer).toHaveBeenCalledWith('abcd1234', 3));
});

test('tests the connection, browses the remote server and starts an import of the ticked files', async () => {
    vi.mocked(api.testConnection).mockResolvedValue({ home: '/home/foo', fingerprint: 'SHA256:abc' });
    vi.mocked(api.listRemote).mockResolvedValue({
        path: '/home/foo',
        entries: [
            { name: 'world', directory: true, size: 0, link: false },
            { name: 'server.jar', directory: false, size: 2048, link: false },
            { name: 'latest.log', directory: false, size: 1, link: true },
        ],
    });
    vi.mocked(api.startImport).mockResolvedValue(transfer({ status: 'queued' }));
    renderScreen(overview(), { dir: '/plugins' });

    expect(await screen.findByText('Test the connection to browse the remote server.')).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Host'), { target: { value: 'files.example.com' } });
    fireEvent.change(screen.getByLabelText('Username'), { target: { value: 'foo' } });
    fireEvent.change(screen.getByLabelText('Password'), { target: { value: 'secret' } });
    fireEvent.click(screen.getByRole('button', { name: 'Test connection' }));

    expect(await screen.findByText('Host key SHA256:abc')).toBeTruthy();
    expect(await screen.findByText('server.jar')).toBeTruthy();
    expect(api.listRemote).toHaveBeenCalledWith('abcd1234', expect.objectContaining({ host: 'files.example.com', password: 'secret' }), '/home/foo');
    expect((screen.getByLabelText('Select latest.log') as HTMLButtonElement).getAttribute('aria-disabled') ?? (screen.getByLabelText('Select latest.log') as HTMLButtonElement).disabled).toBeTruthy();

    fireEvent.click(screen.getByLabelText('Select server.jar'));
    expect(screen.getByText(/1 item from \/home\/foo/)).toBeTruthy();
    expect((screen.getByLabelText('Target folder') as HTMLInputElement).value).toBe('/plugins');

    fireEvent.click(screen.getByRole('button', { name: 'Start import' }));
    await waitFor(() =>
        expect(api.startImport).toHaveBeenCalledWith(
            'abcd1234',
            expect.objectContaining({ root: '/home/foo', selection: ['server.jar'], target: '/plugins', fingerprint: 'SHA256:abc', wipe: false, overwrite: false })
        )
    );
});

test('wiping the target folder needs the server name typed in', async () => {
    vi.mocked(api.testConnection).mockResolvedValue({ home: '/', fingerprint: null });
    vi.mocked(api.listRemote).mockResolvedValue({ path: '/', entries: [] });
    vi.mocked(api.startImport).mockResolvedValue(transfer({ status: 'queued' }));
    renderScreen(overview());

    fireEvent.change(await screen.findByLabelText('Host'), { target: { value: 'files.example.com' } });
    fireEvent.change(screen.getByLabelText('Username'), { target: { value: 'foo' } });
    fireEvent.click(screen.getByRole('button', { name: 'Test connection' }));
    expect(await screen.findByText('This folder is empty.')).toBeTruthy();

    fireEvent.click(screen.getByRole('switch', { name: /Wipe the target folder first/ }));
    expect(screen.getByRole('switch', { name: /Save the current files as an archive first/ })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Start import' }));

    const dialog = await screen.findByRole('dialog');
    fireEvent.click(within(dialog).getByRole('button', { name: 'Wipe and import' }));
    expect(await within(dialog).findByText('The name does not match.')).toBeTruthy();
    expect(api.startImport).not.toHaveBeenCalled();

    fireEvent.change(within(dialog).getByLabelText('Type Survival to confirm'), { target: { value: 'Survival' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Wipe and import' }));
    await waitFor(() => expect(api.startImport).toHaveBeenCalledWith('abcd1234', expect.objectContaining({ wipe: true, archive: true, confirm: 'Survival', root: '/', selection: [] })));
});

test('only offers what the user may do', async () => {
    vi.mocked(api.searchServers).mockResolvedValue([{ identifier: 'lobby123', name: 'Lobby', node: 'Dev Node' }]);
    renderScreen(overview({ features: { import: false, export: false, copy: true }, can: { wipe: false, archive: false, read: false, write: true } }));

    expect(await screen.findByRole('tab', { name: 'Between servers' })).toBeTruthy();
    expect(screen.queryByRole('tab', { name: 'Import' })).toBeNull();
    expect(screen.getByText('No transfers yet.')).toBeTruthy();
    expect(screen.getByText('Pick the server to copy from.')).toBeTruthy();
    await waitFor(() => expect(api.searchServers).toHaveBeenCalledWith('abcd1234', 'in', ''));
});

test('switches the transfer type with the tab bar', async () => {
    renderScreen(overview(), { mode: 'export' });

    const exporting = await screen.findByRole('tab', { name: 'Export' });
    expect(exporting.getAttribute('aria-selected')).toBe('true');

    fireEvent.click(screen.getByRole('tab', { name: 'Import' }));
    expect(screen.getByRole('tab', { name: 'Import' }).getAttribute('aria-selected')).toBe('true');
    expect(exporting.getAttribute('aria-selected')).toBe('false');
});

test('reads the folder and files handed over by the file manager', () => {
    expect(readSearch({ mode: 'export', dir: '/plugins', files: ['a.jar', 5, 'b.yml'] })).toEqual({ mode: 'export', dir: '/plugins', files: ['a.jar', 'b.yml'] });
    expect(readSearch({ mode: 'bogus', dir: 'relative' })).toEqual({ mode: null, dir: '/', files: [] });
    expect(readSearch(undefined)).toEqual({ mode: null, dir: '/', files: [] });
});
