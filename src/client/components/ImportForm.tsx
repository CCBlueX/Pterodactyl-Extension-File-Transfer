import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { Button, Switch, TitledGreyBox, httpErrorToHuman, toast, useCurrentServerRequired } from '@pterodactyl/sdk';
import { startImport, type Connection, type Overview, type Transfer } from '../api';
import { formatBytes, plural } from '../lib/format';
import ConnectionBox, { type Connected } from './ConnectionBox';
import FolderField from './FolderField';
import RemoteBrowser from './RemoteBrowser';
import WipeDialog from './WipeDialog';

interface Props {
    overview: Overview;
    connection: Connection;
    connected: Connected | null;
    onConnection: (connection: Connection, connected: Connected | null) => void;
    initialTarget: string;
    busy: boolean;
    onStarted: (transfer: Transfer) => void;
}

export default function ImportForm({ overview, connection, connected, onConnection, initialTarget, busy, onStarted }: Props) {
    const { identifier, name } = useCurrentServerRequired().attributes;
    const [path, setPath] = useState(connected?.home ?? '/');
    const [selected, setSelected] = useState<string[]>([]);
    const [target, setTarget] = useState(initialTarget);
    const [overwrite, setOverwrite] = useState(false);
    const [wipe, setWipe] = useState(false);
    const [archive, setArchive] = useState(true);
    const [confirming, setConfirming] = useState(false);

    const open = (next: string) => (setPath(next), setSelected([]));
    const start = useMutation({
        mutationFn: (confirm?: string) =>
            startImport(identifier, { ...connection, fingerprint: connected?.fingerprint, root: path, selection: selected, target, overwrite, wipe, archive: wipe && archive, confirm }),
        onSuccess: (transfer) => (setConfirming(false), toast.success('The import started.'), onStarted(transfer)),
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    return (
        <div className='xfer:grid xfer:gap-6 xfer:lg:grid-cols-3'>
            <div className='xfer:min-w-0'>
                <ConnectionBox
                    protocols={overview.protocols}
                    value={connection}
                    connected={connected}
                    onChange={(next) => onConnection(next, null)}
                    onConnected={(result) => (onConnection(connection, result), open(result.home))}
                />
            </div>
            <div className='xfer:grid xfer:min-w-0 xfer:content-start xfer:gap-6 xfer:lg:col-span-2'>
                <TitledGreyBox title='Remote files'>
                    {connected ? (
                        <RemoteBrowser connection={connection} path={path} onOpen={open} selected={selected} onSelect={setSelected} />
                    ) : (
                        <p className='xfer:text-sm xfer:text-muted-foreground'>Test the connection to browse the remote server.</p>
                    )}
                </TitledGreyBox>
                <TitledGreyBox title='Import into this server'>
                    <div className='xfer:grid xfer:gap-4'>
                        <FolderField id='xfer-import-target' label='Target folder' server={identifier} value={target} onChange={setTarget} />
                        <Switch checked={overwrite} onChange={setOverwrite} label='Overwrite existing files' description='Otherwise files that already exist are skipped.' />
                        {overview.can.wipe && <Switch checked={wipe} onChange={setWipe} label='Wipe the target folder first' description='Deletes everything in the target folder before importing.' />}
                        {wipe && overview.can.archive && (
                            <Switch checked={archive} onChange={setArchive} label='Save the current files as an archive first' description='The archive stays in the target folder.' />
                        )}
                        <div className='xfer:flex xfer:flex-wrap xfer:items-center xfer:justify-between xfer:gap-3'>
                            <p className='xfer:text-xs xfer:text-muted-foreground'>
                                {selected.length > 0 ? `${plural(selected.length, 'item')} from ${path}` : `Nothing ticked: the whole folder ${path} is imported`}. Files over{' '}
                                {formatBytes(overview.limits.remote)} are skipped.
                            </p>
                            <Button disabled={!connected || busy} isLoading={start.isPending && !confirming} onClick={() => (wipe ? setConfirming(true) : start.mutate(undefined))}>
                                Start import
                            </Button>
                        </div>
                    </div>
                </TitledGreyBox>
            </div>
            <WipeDialog open={confirming} serverName={name} folder={target} archive={wipe && archive} pending={start.isPending} onClose={() => setConfirming(false)} onConfirmed={(confirm) => start.mutate(confirm)} />
        </div>
    );
}
