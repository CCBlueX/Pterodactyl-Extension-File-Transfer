import { http } from '@pterodactyl/sdk';

export type TransferType = 'import' | 'export' | 'copy';
export type TransferStatus = 'queued' | 'running' | 'cancelling' | 'done' | 'failed' | 'cancelled';

export interface Side {
    kind: 'remote' | 'server';
    label: string;
    identifier: string | null;
    path: string;
}

export interface Transfer {
    id: number;
    type: TransferType;
    status: TransferStatus;
    step: 'wipe' | 'scan' | 'transfer' | 'cleanup' | 'done';
    source: Side;
    target: Side;
    selection: string[];
    options: { overwrite: boolean; wipe: boolean; archive: boolean };
    archive: string | null;
    files: { total: number; ok: number; failed: number; skipped: number };
    bytes: { total: number; done: number };
    current_path: string | null;
    error: string | null;
    user: string | null;
    elapsed: number;
    created_at: string;
    finished_at: string | null;
}

export interface Problem {
    path: string;
    status: 'failed' | 'skipped';
    message: string | null;
}

export interface TransferDetail extends Transfer {
    problems: Problem[];
}

export interface Overview {
    features: Record<TransferType, boolean>;
    protocols: { value: string; label: string }[];
    limits: { remote: number; copy: number };
    can: { wipe: boolean; archive: boolean; read: boolean; write: boolean };
    active: Transfer | null;
    history: Transfer[];
}

export interface Connection {
    protocol: string;
    host: string;
    port: number | null;
    username: string;
    password: string;
    private_key: string;
    passphrase: string;
    insecure: boolean;
    fingerprint?: string | null;
}

export interface Entry {
    name: string;
    directory: boolean;
    size: number;
    link: boolean;
}

export interface ServerOption {
    identifier: string;
    name: string;
    node: string;
}

export interface Selection {
    root: string;
    selection: string[];
}

const base = (id: string) => `/api/client/servers/${id}/extensions/file-transfer`;

/** Archives are created before a transfer starts, which can take a few minutes. */
const START_TIMEOUT = 300_000;

export const keys = {
    overview: (id: string) => ['file-transfer', id] as const,
    transfer: (id: string, transfer: number) => ['file-transfer', id, 'transfer', transfer] as const,
    remote: (id: string, connection: Connection, path: string) => ['file-transfer', id, 'remote', connection.protocol, connection.host, connection.port, connection.username, path] as const,
    servers: (id: string, direction: string, search: string) => ['file-transfer', id, 'servers', direction, search] as const,
};

export const getOverview = async (id: string) => (await http.get<Overview>(base(id))).data;
export const getTransfer = async (id: string, transfer: number) => (await http.get<TransferDetail>(`${base(id)}/transfers/${transfer}`)).data;
export const cancelTransfer = async (id: string, transfer: number) => (await http.post<Transfer>(`${base(id)}/transfers/${transfer}/cancel`)).data;

export const testConnection = async (id: string, connection: Connection) => (await http.post<{ home: string; fingerprint: string | null }>(`${base(id)}/remote/test`, connection)).data;
export const listRemote = async (id: string, connection: Connection, path: string) => (await http.post<{ path: string; entries: Entry[] }>(`${base(id)}/remote/list`, { ...connection, path })).data;

export const searchServers = async (id: string, direction: 'in' | 'out', search: string) =>
    (await http.get<{ data: ServerOption[] }>(`${base(id)}/servers`, { params: { direction, search } })).data.data;

export interface ImportInput extends Connection, Selection {
    target: string;
    overwrite: boolean;
    wipe: boolean;
    archive: boolean;
    confirm?: string;
}

export interface ExportInput extends Connection, Selection {
    target: string;
    overwrite: boolean;
    archive: boolean;
}

export interface CopyInput extends Selection {
    direction: 'in' | 'out';
    server: string;
    target: string;
    overwrite: boolean;
}

export const startImport = async (id: string, input: ImportInput) => (await http.post<Transfer>(`${base(id)}/import`, input, { timeout: START_TIMEOUT })).data;
export const startExport = async (id: string, input: ExportInput) => (await http.post<Transfer>(`${base(id)}/export`, input, { timeout: START_TIMEOUT })).data;
export const startCopy = async (id: string, input: CopyInput) => (await http.post<Transfer>(`${base(id)}/copy`, input)).data;
