import { Button, usePanelNavigate, useServerPermission, type FileManagerSlotData } from '@pterodactyl/sdk';

/** Sends the files ticked in the file manager to the export form. */
export default function ExportSelected({ data }: { data: FileManagerSlotData }) {
    const navigate = usePanelNavigate();
    const canExport = useServerPermission('ext.file-transfer.export');
    const canCopy = useServerPermission('ext.file-transfer.copy');

    if (!canExport && !canCopy) {
        return null;
    }

    return (
        <Button
            onClick={() =>
                navigate({
                    extension: 'file-transfer',
                    screen: 'transfer',
                    params: { id: data.server.attributes.identifier },
                    search: { mode: canExport ? 'export' : 'copy', dir: data.directory, files: [...data.selectedFiles] },
                })
            }
        >
            {canExport ? 'Export' : 'Copy to server'}
        </Button>
    );
}
