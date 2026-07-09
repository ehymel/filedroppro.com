// assets/controllers/layout_controller.js
import { Controller } from '@hotwired/stimulus';
import { Tooltip, Popover, Dropdown } from 'bootstrap';

/* stimulusFetch: 'eager' */
export default class extends Controller {
    connect() {
        // 1. Initialize all Bootstrap tooltips globally within this layout scope
        this.element.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new Tooltip(el));

        // 2. Initialize all Bootstrap popovers
        this.element.querySelectorAll('[data-bs-toggle="popover"]').forEach(el => new Popover(el));

        // Optional: Auto-detect or sync user system color scheme preference (Light/Dark mode)
        this.syncTheme();
    }

    syncTheme() {
        const storedTheme = localStorage.getItem('theme');
        if (storedTheme) {
            document.documentElement.setAttribute('data-bs-theme', storedTheme);
            return;
        }

        // const systemTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        // document.documentElement.setAttribute('data-bs-theme', systemTheme);
        document.documentElement.setAttribute('data-bs-theme', 'light');
    }
}
