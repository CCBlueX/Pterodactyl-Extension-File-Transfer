import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Button, Label, Select, Switch, TitledGreyBox, httpErrorToHuman, toast, useCurrentServerRequired } from '@pterodactyl/sdk';
import { keys, searchServers, startCopy, type Overview, type ServerOption, type Transfer } from '../api';
import { formatBytes, plural } from '../lib/format';
import FolderField from './FolderField';
import ServerBrowser from './ServerBrowser';

interface Props {
    overview: Overview;
    initialFolder: string;
    initialFiles: string[];
    busy: boolean;
    onStarted: (transfer: Transfer) => void;
}

type Direction = 'in' | 'out';

export default function CopyForm({ overview, initialFolder, initialFiles, busy, onStarted }: Props) {
    const { identifier } = useCurrentServerRequired().attributes;
    const [direction, setDirection] = useState<Direction>(overview.can.write ? 'in' : 'out');
    const [search, setSearch] = useState('');
    const [other, setOther] = useState<ServerOption | null>(null);
    const [folder, setFolder] = useState(direction === 'out' ? initialFolder : '/');
    const [selected, setSelected] = useState<string[]>(direction === 'out' ? initialFiles : []);
    const [target, setTarget] = useState(direction === 'in' ? initialFolder : '/');
    const [overwrite, setOverwrite] = useState(false);

    const servers = useQuery({ queryKey: keys.servers(identifier, direction, search), queryFn: () => searchServers(identifier, direction, search), placeholderData: (previous) => previous });
    const options = [...(other ? [other] : []), ...(servers.data ?? []).filter((server) => server.identifier !== other?.identifier)];
    const source = direction === 'in' ? other?.identifier : identifier;
    const targetServer = direction === 'in' ? identifier : other?.identifier;

    const turn = (next: Direction) => (setDirection(next), setOther(null), setFolder('/'), setSelected([]), setTarget('/'));
    const start = useMutation({
        mutationFn: () => startCopy(identifier, { direction, server: other!.identifier, root: folder, selection: selected, target, overwrite }),
        onSuccess: (transfer) => (toast.success('The copy started.'), onStarted(transfer)),
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    const directions = [
        ...(overview.can.write ? [{ value: 'in', label: 'From another server into this one' }] : []),
        ...(overview.can.read ? [{ value: 'out', label: 'From this server to another one' }] : []),
    ];

    return (
        <div className='xfer:grid xfer:gap-6 xfer:lg:grid-cols-3'>
            <div className='xfer:min-w-0'>
                <TitledGreyBox title='Other server'>
                    <div className='xfer:grid xfer:gap-4'>
                        <div>
                            <Label htmlFor='xfer-direction'>Copy</Label>
                            <Select id='xfer-direction' value={direction} onChange={(value) => turn(value as Direction)} options={directions} />
                        </div>
                        <div>
                            <Label htmlFor='xfer-server'>{direction === 'in' ? 'Copy from' : 'Copy to'}</Label>
                            <Select
                                id='xfer-server'
                                value={other?.identifier ?? null}
                                placeholder='Pick a server'
                                searchPlaceholder='Search servers'
                                emptyMessage={servers.isFetching ? 'Loading...' : 'No servers you can use.'}
                                onSearchChange={setSearch}
                                onChange={(value) => (setOther(options.find((server) => server.identifier === value) ?? null), direction === 'in' && (setFolder('/'), setSelected([])))}
                                options={options.map((server) => ({ value: server.identifier, label: `${server.name} (${server.node})`, textLabel: server.name }))}
                            />
                        </div>
                        <p className='xfer:text-xs xfer:text-muted-foreground'>
                            Files are copied through the panel, one at a time. Files over {formatBytes(overview.limits.copy)} are skipped; move those with a backup or SFTP.
                        </p>
                    </div>
                </TitledGreyBox>
            </div>
            <div className='xfer:grid xfer:min-w-0 xfer:content-start xfer:gap-6 xfer:lg:col-span-2'>
                <TitledGreyBox title='Files to copy'>
                    {source ? (
                        <ServerBrowser server={source} path={folder} onOpen={(next) => (setFolder(next), setSelected([]))} selected={selected} onSelect={setSelected} />
                    ) : (
                        <p className='xfer:text-sm xfer:text-muted-foreground'>Pick the server to copy from.</p>
                    )}
                </TitledGreyBox>
                <TitledGreyBox title={direction === 'in' ? 'Copy into this server' : `Copy into ${other?.name ?? 'the other server'}`}>
                    <div className='xfer:grid xfer:gap-4'>
                        {targetServer && <FolderField id='xfer-copy-target' label='Target folder' server={targetServer} value={target} onChange={setTarget} />}
                        <Switch checked={overwrite} onChange={setOverwrite} label='Overwrite existing files' description='Otherwise files that already exist are skipped.' />
                        <div className='xfer:flex xfer:flex-wrap xfer:items-center xfer:justify-between xfer:gap-3'>
                            <p className='xfer:text-xs xfer:text-muted-foreground'>
                                {selected.length > 0 ? `${plural(selected.length, 'item')} from ${folder}` : `Nothing ticked: the whole folder ${folder} is copied`}.
                            </p>
                            <Button disabled={!other || busy} isLoading={start.isPending} onClick={() => start.mutate()}>
                                Start copy
                            </Button>
                        </div>
                    </div>
                </TitledGreyBox>
            </div>
        </div>
    );
}
