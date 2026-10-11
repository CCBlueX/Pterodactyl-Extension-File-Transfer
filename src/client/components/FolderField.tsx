import { useState } from 'react';
import { Button, Dialog, Input, Label } from '@pterodactyl/sdk';
import ServerBrowser from './ServerBrowser';

interface Props {
    id: string;
    label: string;
    server: string;
    value: string;
    onChange: (path: string) => void;
}

/** A folder on a server: typed in or picked from its folders. */
export default function FolderField({ id, label, server, value, onChange }: Props) {
    const [browsing, setBrowsing] = useState<string | null>(null);

    return (
        <div>
            <Label htmlFor={id}>{label}</Label>
            <div className='xfer:flex xfer:gap-2'>
                <Input id={id} className='xfer:font-mono' value={value} onChange={(event) => onChange(event.currentTarget.value)} />
                <Button.Text className='xfer:shrink-0' onClick={() => setBrowsing(value || '/')}>
                    Browse
                </Button.Text>
            </div>
            {browsing !== null && (
                <Dialog open onClose={() => setBrowsing(null)} title='Pick a folder'>
                    <ServerBrowser server={server} path={browsing} onOpen={setBrowsing} foldersOnly />
                    <Dialog.Footer>
                        <Button.Text className='xfer:w-full xfer:sm:w-auto' onClick={() => setBrowsing(null)}>
                            Cancel
                        </Button.Text>
                        <Button className='xfer:w-full xfer:sm:w-auto' onClick={() => (onChange(browsing), setBrowsing(null))}>
                            Use {browsing}
                        </Button>
                    </Dialog.Footer>
                </Dialog>
            )}
        </div>
    );
}
