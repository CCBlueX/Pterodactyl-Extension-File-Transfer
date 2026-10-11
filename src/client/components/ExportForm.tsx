import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { Button, Switch, TitledGreyBox, httpErrorToHuman, toast, useCurrentServerRequired } from '@pterodactyl/sdk';
import { startExport, type Connection, type Overview, type Transfer } from '../api';
import { formatBytes, plural } from '../lib/format';
import ConnectionBox, { type Connected } from './ConnectionBox';
import RemoteBrowser from './RemoteBrowser';
import ServerBrowser from './ServerBrowser';

interface Props {
    overview: Overview;
    connection: Connection;
    connected: Connected | null;
    onConnection: (connection: Connection, connected: Connected | null) => void;
    initialFolder: string;
    initialFiles: string[];
    busy: boolean;
    onStarted: (transfer: Transfer) => void;
}

export default function ExportForm({ overview, connection, connected, onConnection, initialFolder, initialFiles, busy, onStarted }: Props) {
    const { identifier } = useCurrentServerRequired().attributes;
    const [folder, setFolder] = useState(initialFolder);
    const [selected, setSelected] = useState<string[]>(initialFiles);
    const [remote, setRemote] = useState(connected?.home ?? '/');
    const [archive, setArchive] = useState(false);
    const [overwrite, setOverwrite] = useState(false);

    const start = useMutation({
        mutationFn: () => startExport(identifier, { ...connection, fingerprint: connected?.fingerprint, root: folder, selection: selected, target: remote, overwrite, archive }),
        onSuccess: (transfer) => (toast.success('The export started.'), onStarted(transfer)),
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
                    onConnected={(result) => (onConnection(connection, result), setRemote(result.home))}
                />
            </div>
            <div className='xfer:grid xfer:min-w-0 xfer:content-start xfer:gap-6 xfer:lg:col-span-2'>
                <TitledGreyBox title='Files to export'>
                    <ServerBrowser server={identifier} path={folder} onOpen={(next) => (setFolder(next), setSelected([]))} selected={selected} onSelect={setSelected} />
                </TitledGreyBox>
                <TitledGreyBox title='Send to'>
                    <div className='xfer:grid xfer:gap-4'>
                        {connected ? (
                            <>
                                <RemoteBrowser connection={connection} path={remote} onOpen={setRemote} foldersOnly />
                                <p className='xfer:text-xs xfer:text-muted-foreground'>
                                    Files go into <span className='xfer:font-mono xfer:text-foreground'>{remote}</span> on the remote server. Open a folder to send them there.
                                </p>
                            </>
                        ) : (
                            <p className='xfer:text-sm xfer:text-muted-foreground'>Test the connection to pick a remote folder.</p>
                        )}
                        {overview.can.archive && (
                            <Switch checked={archive} onChange={setArchive} label='Send as one archive' description='Packs the files into a .tar.gz first and removes it from the server afterwards.' />
                        )}
                        <Switch checked={overwrite} onChange={setOverwrite} label='Overwrite existing files' description='Otherwise files that already exist on the remote server are skipped.' />
                        <div className='xfer:flex xfer:flex-wrap xfer:items-center xfer:justify-between xfer:gap-3'>
                            <p className='xfer:text-xs xfer:text-muted-foreground'>
                                {selected.length > 0 ? `${plural(selected.length, 'item')} from ${folder}` : `Nothing ticked: the whole folder ${folder} is exported`}. Files over{' '}
                                {formatBytes(overview.limits.remote)} are skipped.
                            </p>
                            <Button disabled={!connected || busy} isLoading={start.isPending} onClick={() => start.mutate()}>
                                {archive && start.isPending ? 'Creating archive...' : 'Start export'}
                            </Button>
                        </div>
                    </div>
                </TitledGreyBox>
            </div>
        </div>
    );
}
