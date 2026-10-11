import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { Button, Checkbox, Input, Label, Select, TextArea, TitledGreyBox, httpErrorToHuman, toast, useCurrentServerRequired } from '@pterodactyl/sdk';
import { testConnection, type Connection } from '../api';

export interface Connected {
    home: string;
    fingerprint: string | null;
}

interface Props {
    protocols: { value: string; label: string }[];
    value: Connection;
    connected: Connected | null;
    onChange: (connection: Connection) => void;
    onConnected: (connected: Connected) => void;
}

export const emptyConnection = (protocol = 'sftp'): Connection => ({ protocol, host: '', port: null, username: '', password: '', private_key: '', passphrase: '', insecure: false });

/** Login details for the remote server. They are only kept in this page and while a transfer runs. */
export default function ConnectionBox({ protocols, value, connected, onChange, onConnected }: Props) {
    const { identifier } = useCurrentServerRequired().attributes;
    const [useKey, setUseKey] = useState(false);
    const set = (changes: Partial<Connection>) => onChange({ ...value, ...changes, fingerprint: null });
    const sftp = value.protocol === 'sftp';

    const test = useMutation({
        mutationFn: () => testConnection(identifier, value),
        onSuccess: (result) => (onConnected(result), toast.success('Connected.')),
        onError: (error) => toast.error(httpErrorToHuman(error)),
    });

    return (
        <TitledGreyBox title='Remote server'>
            <div className='xfer:grid xfer:gap-4'>
                <div>
                    <Label htmlFor='xfer-protocol'>Protocol</Label>
                    <Select id='xfer-protocol' value={value.protocol} onChange={(protocol) => set({ protocol: String(protocol), port: null })} options={protocols} />
                </div>
                <div className='xfer:grid xfer:grid-cols-3 xfer:gap-4'>
                    <div className='xfer:col-span-2'>
                        <Label htmlFor='xfer-host'>Host</Label>
                        <Input id='xfer-host' value={value.host} placeholder='files.example.com' autoComplete='off' onChange={(event) => set({ host: event.currentTarget.value })} />
                    </div>
                    <div>
                        <Label htmlFor='xfer-port'>Port</Label>
                        <Input
                            id='xfer-port'
                            type='number'
                            min={1}
                            max={65535}
                            value={value.port ?? ''}
                            placeholder={sftp ? '22' : '21'}
                            onChange={(event) => set({ port: event.currentTarget.value === '' ? null : Number(event.currentTarget.value) })}
                        />
                    </div>
                </div>
                <div>
                    <Label htmlFor='xfer-username'>Username</Label>
                    <Input id='xfer-username' value={value.username} autoComplete='off' onChange={(event) => set({ username: event.currentTarget.value })} />
                </div>
                {sftp && (
                    <div>
                        <Label htmlFor='xfer-auth'>Log in with</Label>
                        <Select
                            id='xfer-auth'
                            value={useKey ? 'key' : 'password'}
                            onChange={(choice) => (setUseKey(choice === 'key'), set({ password: '', private_key: '', passphrase: '' }))}
                            options={[
                                { value: 'password', label: 'Password' },
                                { value: 'key', label: 'Private key' },
                            ]}
                        />
                    </div>
                )}
                {sftp && useKey ? (
                    <>
                        <div>
                            <Label htmlFor='xfer-key'>Private key</Label>
                            <TextArea id='xfer-key' rows={4} className='xfer:font-mono xfer:text-xs' value={value.private_key} placeholder='-----BEGIN OPENSSH PRIVATE KEY-----' onChange={(event) => set({ private_key: event.currentTarget.value })} />
                        </div>
                        <div>
                            <Label htmlFor='xfer-passphrase'>Passphrase</Label>
                            <Input id='xfer-passphrase' type='password' autoComplete='new-password' value={value.passphrase} placeholder='Only if the key has one' onChange={(event) => set({ passphrase: event.currentTarget.value })} />
                        </div>
                    </>
                ) : (
                    <div>
                        <Label htmlFor='xfer-password'>Password</Label>
                        <Input id='xfer-password' type='password' autoComplete='new-password' value={value.password} onChange={(event) => set({ password: event.currentTarget.value })} />
                    </div>
                )}
                {value.protocol === 'ftps' && (
                    <label className='xfer:flex xfer:items-center xfer:gap-2 xfer:text-sm'>
                        <Checkbox checked={value.insecure} onChange={(insecure) => set({ insecure })} />
                        Allow self-signed certificates
                    </label>
                )}
                <div className='xfer:flex xfer:flex-wrap xfer:items-center xfer:justify-between xfer:gap-3'>
                    <p className='xfer:min-w-0 xfer:text-xs xfer:text-muted-foreground'>
                        {connected ? (
                            <span className='xfer:text-success'>Connected.</span>
                        ) : (
                            'The login is only stored while a transfer runs.'
                        )}
                        {connected?.fingerprint && <span className='xfer:mt-1 xfer:block xfer:break-all xfer:font-mono'>Host key {connected.fingerprint}</span>}
                    </p>
                    <Button size='small' isLoading={test.isPending} disabled={value.host === '' || value.username === ''} onClick={() => test.mutate()}>
                        Test connection
                    </Button>
                </div>
            </div>
        </TitledGreyBox>
    );
}
