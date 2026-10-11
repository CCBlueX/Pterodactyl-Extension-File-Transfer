import { Button, NamedIcon, usePanelNavigate, useServerPermission, type FileManagerSlotData } from '@pterodactyl/sdk';

export const PERMISSIONS = ['ext.file-transfer.import', 'ext.file-transfer.export', 'ext.file-transfer.copy'];

/** A way into the Transfer screen from the file manager, starting in the folder that is open. */
export default function TransferLink({ data }: { data: FileManagerSlotData }) {
    const navigate = usePanelNavigate();

    if (!useServerPermission(PERMISSIONS, true)) {
        return null;
    }

    return (
        <Button.Text size='small' onClick={() => navigate({ extension: 'file-transfer', screen: 'transfer', params: { id: data.server.attributes.identifier }, search: { dir: data.directory } })}>
            <span className='xfer:inline-flex xfer:items-center xfer:gap-2'>
                <NamedIcon name='arrow-left-right' className='xfer:h-4 xfer:w-4' />
                Transfer
            </span>
        </Button.Text>
    );
}
