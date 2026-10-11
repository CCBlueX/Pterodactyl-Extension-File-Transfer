import './styles.css';
import { definePterodactylExtension } from '@pterodactyl/sdk';
import ExportSelected from './components/ExportSelected';
import TransferLink from './components/TransferLink';

export default definePterodactylExtension({
    setup({ screens, slots }) {
        screens.register('transfer', () => import('./screens/TransferScreen'));
        slots.register('server.files.toolbar', TransferLink);
        slots.register('server.files.selectionActions', ExportSelected);
    },
});
