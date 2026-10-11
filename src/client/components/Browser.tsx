import { Checkbox, NamedIcon, Table, Tooltip, httpErrorToHuman } from '@pterodactyl/sdk';
import type { Entry } from '../api';
import { formatBytes, joinPath } from '../lib/format';

interface Props {
    path: string;
    entries: Entry[] | undefined;
    loading: boolean;
    error?: unknown;
    onOpen: (path: string) => void;
    /** Ticked names in the current folder; leave out for a folder picker. */
    selected?: string[];
    onSelect?: (names: string[]) => void;
    foldersOnly?: boolean;
    placeholder?: string;
}

/** A file list in the style of the file manager, used for remote and server folders. */
export default function Browser({ path, entries, loading, error, onOpen, selected, onSelect, foldersOnly = false, placeholder }: Props) {
    const rows = (entries ?? []).filter((entry) => !foldersOnly || entry.directory);
    const selectable = rows.filter((entry) => !entry.link);
    const ticked = selected ?? [];
    const allTicked = selectable.length > 0 && selectable.every((entry) => ticked.includes(entry.name));
    const toggle = (name: string, checked: boolean) => onSelect?.(checked ? [...ticked, name] : ticked.filter((value) => value !== name));

    return (
        <div className='xfer:grid xfer:gap-3'>
            <Breadcrumbs path={path} onOpen={onOpen} />
            <Table
                rows={rows}
                keyOf={(entry) => entry.name}
                isFetching={loading}
                emptyState={placeholder ?? (loading ? 'Loading...' : error ? httpErrorToHuman(error) : foldersOnly ? 'There are no folders here.' : 'This folder is empty.')}
                columns={[
                    {
                        id: 'name',
                        label: onSelect ? (
                            <span className='xfer:flex xfer:items-center xfer:gap-3'>
                                <Checkbox aria-label='Select all' checked={allTicked} indeterminate={!allTicked && ticked.length > 0} onChange={(checked) => onSelect(checked ? selectable.map((entry) => entry.name) : [])} />
                                Name
                            </span>
                        ) : (
                            'Name'
                        ),
                        cell: (entry: Entry) => (
                            <span className='xfer:flex xfer:min-w-0 xfer:items-center xfer:gap-3'>
                                {onSelect && <Tick entry={entry} checked={ticked.includes(entry.name)} onChange={(checked) => toggle(entry.name, checked)} />}
                                <NamedIcon name={entry.link ? 'link' : entry.directory ? 'folder' : 'file'} className='xfer:h-4 xfer:w-4 xfer:shrink-0 xfer:text-muted-foreground' />
                                {entry.directory && !entry.link ? (
                                    <button type='button' className='xfer:truncate xfer:text-left xfer:text-foreground xfer:hover:underline' onClick={() => onOpen(joinPath(path, entry.name))}>
                                        {entry.name}
                                    </button>
                                ) : (
                                    <span className='xfer:truncate xfer:text-foreground'>{entry.name}</span>
                                )}
                            </span>
                        ),
                    },
                    { id: 'size', label: 'Size', cell: (entry: Entry) => (entry.directory ? '–' : formatBytes(entry.size)) },
                ]}
            />
        </div>
    );
}

function Tick({ entry, checked, onChange }: { entry: Entry; checked: boolean; onChange: (checked: boolean) => void }) {
    if (!entry.link) {
        return <Checkbox aria-label={`Select ${entry.name}`} checked={checked} onChange={onChange} />;
    }

    return (
        <Tooltip content='Links and special files are skipped.'>
            <span className='xfer:inline-flex'>
                <Checkbox aria-label={`Select ${entry.name}`} checked={false} disabled onChange={() => undefined} />
            </span>
        </Tooltip>
    );
}

function Breadcrumbs({ path, onOpen }: { path: string; onOpen: (path: string) => void }) {
    const parts = path.split('/').filter(Boolean);

    return (
        <nav aria-label='Folder' className='xfer:flex xfer:min-w-0 xfer:flex-wrap xfer:items-center xfer:gap-1 xfer:font-mono xfer:text-sm xfer:text-muted-foreground'>
            <button type='button' aria-label='Top folder' className='xfer:inline-flex xfer:items-center xfer:hover:text-foreground' onClick={() => onOpen('/')}>
                <NamedIcon name='house' className='xfer:h-4 xfer:w-4' />
            </button>
            {parts.map((part, index) => (
                <span key={index} className='xfer:inline-flex xfer:items-center xfer:gap-1'>
                    <span>/</span>
                    <button type='button' className='xfer:hover:text-foreground' onClick={() => onOpen(`/${parts.slice(0, index + 1).join('/')}`)}>
                        {part}
                    </button>
                </span>
            ))}
        </nav>
    );
}
