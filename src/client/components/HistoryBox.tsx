import { useState } from 'react';
import { Button, Table, TitledGreyBox } from '@pterodactyl/sdk';
import type { Transfer } from '../api';
import { describe, formatBytes, formatDuration } from '../lib/format';
import { StatusPill } from './Pill';
import TransferDialog from './TransferDialog';

export default function HistoryBox({ history, current }: { history: Transfer[]; current: string }) {
    const [open, setOpen] = useState<Transfer | null>(null);

    return (
        <TitledGreyBox title='Recent transfers' className='xfer:mt-6' contentClassName='xfer:p-0'>
            <Table
                rows={history}
                keyOf={(transfer) => String(transfer.id)}
                emptyState='No transfers yet.'
                columns={[
                    {
                        id: 'transfer',
                        label: 'Transfer',
                        cell: (transfer) => (
                            <div className='xfer:min-w-0'>
                                <p className='xfer:truncate xfer:text-foreground'>{describe(transfer, current)}</p>
                                <p className='xfer:text-xs xfer:text-muted-foreground'>
                                    {new Date(transfer.created_at).toLocaleString()}
                                    {transfer.user && ` by ${transfer.user}`}
                                </p>
                            </div>
                        ),
                    },
                    { id: 'status', label: 'Status', cell: (transfer) => <StatusPill status={transfer.status} failed={transfer.files.failed} /> },
                    {
                        id: 'files',
                        label: 'Files',
                        cell: (transfer) => (
                            <span className='xfer:whitespace-nowrap'>
                                {transfer.files.ok.toLocaleString()} copied
                                {transfer.files.failed > 0 && <span className='xfer:text-destructive'>, {transfer.files.failed.toLocaleString()} failed</span>}
                                {transfer.files.skipped > 0 && `, ${transfer.files.skipped.toLocaleString()} skipped`}
                            </span>
                        ),
                    },
                    { id: 'size', label: 'Data', cell: (transfer) => formatBytes(transfer.bytes.done) },
                    { id: 'time', label: 'Took', cell: (transfer) => formatDuration(transfer.elapsed) },
                    {
                        id: 'details',
                        label: '',
                        cell: (transfer) => (
                            <div className='xfer:flex xfer:justify-end'>
                                <Button.Text size='xsmall' onClick={() => setOpen(transfer)}>
                                    Details
                                </Button.Text>
                            </div>
                        ),
                    },
                ]}
            />
            {open && <TransferDialog transfer={open} current={current} onClose={() => setOpen(null)} />}
        </TitledGreyBox>
    );
}
