import { useServerFiles } from '@pterodactyl/sdk';
import type { Entry } from '../api';
import Browser from './Browser';

interface Props {
    server: string;
    path: string;
    onOpen: (path: string) => void;
    selected?: string[];
    onSelect?: (names: string[]) => void;
    foldersOnly?: boolean;
}

export default function ServerBrowser({ server, path, ...props }: Props) {
    const files = useServerFiles(server, path, (data) =>
        data.data.map(({ attributes }): Entry => ({ name: attributes.name, directory: !attributes.is_file, size: attributes.size ?? 0, link: attributes.is_symlink }))
    );

    return <Browser path={path} entries={files.data} loading={files.isFetching} error={files.error} {...props} />;
}
