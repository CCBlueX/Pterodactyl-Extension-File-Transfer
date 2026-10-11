import { useQuery } from '@tanstack/react-query';
import { useCurrentServerRequired } from '@pterodactyl/sdk';
import { keys, listRemote, type Connection } from '../api';
import Browser from './Browser';

interface Props {
    connection: Connection;
    path: string;
    onOpen: (path: string) => void;
    selected?: string[];
    onSelect?: (names: string[]) => void;
    foldersOnly?: boolean;
}

export default function RemoteBrowser({ connection, path, ...props }: Props) {
    const { identifier } = useCurrentServerRequired().attributes;
    const listing = useQuery({ queryKey: keys.remote(identifier, connection, path), queryFn: () => listRemote(identifier, connection, path), retry: false, staleTime: 30_000 });

    return <Browser path={path} entries={listing.data?.entries} loading={listing.isFetching} error={listing.error} {...props} />;
}
