// Import all of Bootstrap's JS
import * as bootstrap from 'bootstrap';
import { Html5Qrcode } from 'html5-qrcode';
import Sortable from 'sortablejs';
import Alpine from 'alpinejs';

// Expose libraries to the global window object
window.bootstrap = bootstrap;
window.Html5Qrcode = Html5Qrcode;
window.Sortable = Sortable;
window.Alpine = Alpine;

Alpine.start();
