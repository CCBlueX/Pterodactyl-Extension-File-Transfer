import { useState } from 'react';
import { Dialog, Input, Label } from '@pterodactyl/sdk';

interface Props {
    open: boolean;
    serverName: string;
    folder: string;
    archive: boolean;
    pending: boolean;
    onClose: () => void;
    onConfirmed: (confirm: string) => void;
}

export default function WipeDialog({ open, serverName, folder, archive, pending, onClose, onConfirmed }: Props) {
    const [typed, setTyped] = useState('');
    const [wrong, setWrong] = useState(false);

    return (
        <Dialog.Confirm
            open={open}
            onClose={() => (setTyped(''), setWrong(false), onClose())}
            title={`Wipe ${folder} first?`}
            confirm='Wipe and import'
            pending={pending}
            onConfirmed={() => (typed === serverName ? onConfirmed(typed) : setWrong(true))}
        >
            <p>
                Everything in <span className='xfer:font-mono'>{folder}</span> is deleted before the import starts.
                {archive ? ' The current files are saved as an archive in that folder first.' : ' This cannot be undone.'}
            </p>
            <div className='xfer:mt-4'>
                <Label htmlFor='xfer-confirm'>Type {serverName} to confirm</Label>
                <Input id='xfer-confirm' value={typed} $hasError={wrong} autoComplete='off' onChange={(event) => (setTyped(event.currentTarget.value), setWrong(false))} />
                {wrong && <p className='xfer:mt-1 xfer:text-xs xfer:text-destructive'>The name does not match.</p>}
            </div>
        </Dialog.Confirm>
    );
}
