import { useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
    ServerContentBlock,
    Spinner,
    httpErrorToHuman,
    invalidateServerData,
    toast,
    useCurrentServerRequired,
    type ScreenComponentProps,
} from '@pterodactyl/sdk';
import { cancelTransfer, getOverview, keys, type Connection, type Overview, type Transfer, type TransferType } from '../api';
import { emptyConnection, type Connected } from '../components/ConnectionBox';
import CopyForm from '../components/CopyForm';
import ExportForm from '../components/ExportForm';
import HistoryBox from '../components/HistoryBox';
import ImportForm from '../components/ImportForm';
import ProgressBox from '../components/ProgressBox';
import Tabs from '../components/Tabs';

const MODES: { id: TransferType; label: string }[] = [
    { id: 'import', label: 'Import' },
    { id: 'export', label: 'Export' },
    { id: 'copy', label: 'Between servers' },
];

/** The folder and files the file manager handed over. */
export function readSearch(search: unknown): { mode: TransferType | null; dir: string; files: string[] } {
    const values = (search !== null && typeof search === 'object' ? search : {}) as Record<string, unknown>;
    const mode = MODES.find((candidate) => candidate.id === values.mode)?.id ?? null;

    return {
        mode,
        dir: typeof values.dir === 'string' && values.dir.startsWith('/') ? values.dir : '/',
        files: Array.isArray(values.files) ? values.files.filter((file): file is string => typeof file === 'string') : [],
    };
}

export default function TransferScreen({ data }: ScreenComponentProps) {
    const { identifier } = useCurrentServerRequired().attributes;
    const queryClient = useQueryClient();
    const search = readSearch(data.search);
    const overview = useQuery({ queryKey: keys.overview(identifier), queryFn: () => getOverview(identifier), refetchInterval: (query) => (query.state.data?.active ? 2000 : false) });
    const [mode, setMode] = useState<TransferType | null>(search.mode);
    const [connection, setConnection] = useState<Connection>(emptyConnection());
    const [connected, setConnected] = useState<Connected | null>(null);
    const active = overview.data?.active ?? null;
    const wasActive = useRef(false);

    useEffect(() => {
        if (wasActive.current && !active) {
            void invalidateServerData(identifier, ['files']);
        }

        wasActive.current = active !== null;
    }, [active, identifier]);

    const protocols = overview.data?.protocols ?? [];

    useEffect(() => {
        if (protocols.length > 0 && !protocols.some((protocol) => protocol.value === connection.protocol)) {
            setConnection(emptyConnection(protocols[0].value));
        }
    }, [protocols, connection.protocol]);

    const cancelling = useMutation({
        mutationFn: (transfer: Transfer) => cancelTransfer(identifier, transfer.id),
        onSuccess: (transfer) => queryClient.setQueryData<Overview>(keys.overview(identifier), (old) => (old ? { ...old, active: transfer } : old)),
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const started = (transfer: Transfer) => {
        queryClient.setQueryData<Overview>(keys.overview(identifier), (old) => (old ? { ...old, active: transfer } : old));
        void queryClient.invalidateQueries({ queryKey: keys.overview(identifier) });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    if (!overview.data) {
        return (
            <ServerContentBlock title='Transfer'>
                {overview.isError ? <p className='xfer:text-sm xfer:text-destructive'>{httpErrorToHuman(overview.error)}</p> : <Spinner size='large' centered />}
            </ServerContentBlock>
        );
    }

    const { features, history } = overview.data;
    const modes = MODES.filter((candidate) => features[candidate.id]);
    const current = modes.find((candidate) => candidate.id === mode)?.id ?? modes[0]?.id ?? null;
    const remote = { connection, connected, onConnection: (next: Connection, result: Connected | null) => (setConnection(next), setConnected(result)) };
    const busy = active !== null;

    return (
        <ServerContentBlock title='Transfer'>
            {active && <ProgressBox transfer={active} current={identifier} canCancel cancelling={cancelling.isPending} onCancel={() => cancelling.mutate(active)} />}
            {modes.length === 0 ? (
                <p className='xfer:text-sm xfer:text-muted-foreground'>Transfers are turned off for this server or you are missing the file permissions they need.</p>
            ) : (
                <>
                    <Tabs label='Transfer type' tabs={modes} active={current ?? modes[0].id} onChange={setMode} />
                    {busy && <p className='xfer:mb-4 xfer:text-sm xfer:text-muted-foreground'>A new transfer can start once the running one is done.</p>}
                    {current === 'import' && <ImportForm overview={overview.data} {...remote} initialTarget={search.dir} busy={busy} onStarted={started} />}
                    {current === 'export' && <ExportForm overview={overview.data} {...remote} initialFolder={search.dir} initialFiles={search.files} busy={busy} onStarted={started} />}
                    {current === 'copy' && <CopyForm overview={overview.data} initialFolder={search.dir} initialFiles={search.files} busy={busy} onStarted={started} />}
                </>
            )}
            <HistoryBox history={history} current={identifier} />
        </ServerContentBlock>
    );
}
