import { useState } from 'react';
import { createPortal } from 'react-dom';
import { Button, NamedIcon, usePanelNavigate, useServerPermission, type FileManagerSlotData } from '@pterodactyl/sdk';
import { toolbarButtonClass, useToolbarSpot } from '../lib/fileToolbar';

export const PERMISSIONS = ['ext.file-transfer.import', 'ext.file-transfer.export', 'ext.file-transfer.copy'];

/** A way into the Transfer screen next to "New folder", starting in the folder that is open. */
export default function TransferLink({ data }: { data: FileManagerSlotData }) {
    const navigate = usePanelNavigate();
    const [slot, setSlot] = useState<HTMLDivElement | null>(null);
    const { host, shared } = useToolbarSpot(slot);

    if (!useServerPermission(PERMISSIONS, true)) {
        return null;
    }

    const button = (
        <Button
            isSecondary
            className={toolbarButtonClass(shared)}
            onClick={() => navigate({ extension: 'file-transfer', screen: 'transfer', params: { id: data.server.attributes.identifier }, search: { dir: data.directory } })}
        >
            <span className='xfer:inline-flex xfer:items-center xfer:gap-2'>
                <NamedIcon name='arrow-left-right' aria-hidden className='xfer:h-4 xfer:w-4' />
                Transfer
            </span>
        </Button>
    );

    return (
        <div ref={setSlot} className={host ? 'xfer:hidden' : 'xfer:mr-4 xfer:mb-4 xfer:inline-flex xfer:align-top'}>
            {host ? createPortal(button, host) : button}
        </div>
    );
}
