import { registerPlugin } from '@wordpress/plugins';
import GDTGSidebar from './sidebar';

registerPlugin( 'gdtg-sidebar-plugin', {
	render: GDTGSidebar,
} );
