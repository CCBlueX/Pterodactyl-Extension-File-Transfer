import { useState } from 'react';
import { Button, Dialog, ProgressBar, Stat, TitledGreyBox } from '@pterodactyl/sdk';
import type { Transfer } from '../api';
import { STEP_LABELS, describe, formatBytes, formatDuration, plural } from '../lib/format';
import { StatusPill } from './Pill';

interface Props {
    transfer: Transfer;
    current: string;
    canCancel: boolean;
    cancelling: boolean;
    onCancel: () => void;
}

export default function ProgressBox({ transfer, current, canCancel, cancelling, onCancel }: Props) {
    const [confirming, setConfirming] = useState(false);
    const { files, bytes } = transfer;
    const done = files.ok + files.failed + files.skipped;
    const scanning = transfer.step === 'scan' || transfer.step === 'wipe';
    const percent = files.total > 0 ? Math.min(100, Math.round((done / files.total) * 100)) : 0;
    const status = transfer.status === 'queued' ? 'Waiting for the queue' : transfer.status === 'cancelling' ? 'Stopping after the current file' : STEP_LABELS[transfer.step];

    return (
        <TitledGreyBox title={describe(transfer, current)} className='xfer:mb-6'>
            <div className='xfer:grid xfer:gap-4'>
                <div className='xfer:flex xfer:flex-wrap xfer:items-center xfer:justify-between xfer:gap-3'>
                    <div className='xfer:flex xfer:items-center xfer:gap-3'>
                        <StatusPill status={transfer.status} />
                        <span className='xfer:text-sm xfer:text-foreground'>{status}</span>
                    </div>
                    {canCancel && transfer.status !== 'cancelling' && (
                        <Button.Danger size='small' onClick={() => setConfirming(true)}>
                            Cancel
                        </Button.Danger>
                    )}
                </div>
                <div>
                    <ProgressBar value={scanning ? null : percent} label='Progress' className={scanning ? 'xfer:animate-pulse' : undefined} />
                    <p className='xfer:mt-2 xfer:text-xs xfer:text-muted-foreground'>
                        {scanning ? `Found ${plural(files.total, 'file')} so far.` : `${done.toLocaleString()} of ${plural(files.total, 'file')}, ${formatBytes(bytes.done)} of ${formatBytes(bytes.total)}.`}
                    </p>
                </div>
                <div className='xfer:grid xfer:grid-cols-2 xfer:gap-3 xfer:sm:grid-cols-4'>
                    <Stat variant='inline' label='Copied' value={files.ok.toLocaleString()} />
                    <Stat variant='inline' label='Failed' value={files.failed.toLocaleString()} tone={files.failed > 0 ? 'danger' : undefined} />
                    <Stat variant='inline' label='Skipped' value={files.skipped.toLocaleString()} />
                    <Stat variant='inline' label='Elapsed' value={formatDuration(transfer.elapsed)} />
                </div>
                {transfer.current_path && (
                    <p className='xfer:truncate xfer:font-mono xfer:text-xs xfer:text-muted-foreground' title={transfer.current_path}>
                        {transfer.current_path}
                    </p>
                )}
                {transfer.error && <p className='xfer:text-sm xfer:text-warning'>{transfer.error}</p>}
            </div>
            <Dialog.Confirm
                open={confirming}
                onClose={() => setConfirming(false)}
                title='Cancel this transfer?'
                confirm='Cancel transfer'
                pending={cancelling}
                onConfirmed={() => (onCancel(), setConfirming(false))}
            >
                Files that are already copied stay where they are.
            </Dialog.Confirm>
        </TitledGreyBox>
    );
}

