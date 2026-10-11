import { useState } from 'react';
import { Button, Dialog, TitledGreyBox } from '@pterodactyl/sdk';
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
                    <div
                        className='xfer:h-2 xfer:w-full xfer:overflow-hidden xfer:rounded-sm xfer:bg-sunken'
                        role='progressbar'
                        aria-label='Progress'
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-valuenow={scanning ? undefined : percent}
                    >
                        <div className={`xfer:h-2 xfer:rounded-sm xfer:bg-primary ${scanning ? 'xfer:animate-pulse' : ''}`} style={{ width: scanning ? '100%' : `${percent}%` }} />
                    </div>
                    <p className='xfer:mt-2 xfer:text-xs xfer:text-muted-foreground'>
                        {scanning ? `Found ${plural(files.total, 'file')} so far.` : `${done.toLocaleString()} of ${plural(files.total, 'file')}, ${formatBytes(bytes.done)} of ${formatBytes(bytes.total)}.`}
                    </p>
                </div>
                <dl className='xfer:grid xfer:grid-cols-2 xfer:gap-3 xfer:text-sm xfer:sm:grid-cols-4'>
                    <Stat label='Copied' value={files.ok.toLocaleString()} />
                    <Stat label='Failed' value={files.failed.toLocaleString()} tone={files.failed > 0 ? 'xfer:text-destructive' : undefined} />
                    <Stat label='Skipped' value={files.skipped.toLocaleString()} />
                    <Stat label='Elapsed' value={formatDuration(transfer.elapsed)} />
                </dl>
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

function Stat({ label, value, tone }: { label: string; value: string; tone?: string }) {
    return (
        <div className='xfer:rounded-sm xfer:bg-sunken xfer:px-3 xfer:py-2'>
            <dt className='xfer:text-xs xfer:text-muted-foreground'>{label}</dt>
            <dd className={`xfer:font-medium ${tone ?? 'xfer:text-foreground'}`}>{value}</dd>
        </div>
    );
}
