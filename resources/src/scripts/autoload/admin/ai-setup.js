class ExoAiSetup {

    constructor() {
        this.selectSelector = '#exmoau_ai_behaviour_mode';
        this.paneSelector = '.exmoau-settings__behaviour-pane';
        this.hiddenClass = 'exmoau-is-hidden';
        this.handleChange = this.handleChange.bind(this);
        this.isInitialized = false;
    }

    init() {
        if (this.isInitialized) {
            return;
        }

        const select = document.querySelector(this.selectSelector);
        const panes = Array.from(document.querySelectorAll(this.paneSelector));

        if (!select || panes.length === 0) {
            return;
        }

        this.select = select;
        this.panes = panes;
        this.isInitialized = true;

        this.updateVisibility(this.select.value);
        this.select.addEventListener('change', this.handleChange);
    }

    handleChange(event) {
        const value = (event && event.target ? event.target.value : '');
        this.updateVisibility(value);
    }

    updateVisibility(mode) {
        if (!Array.isArray(this.panes) || this.panes.length === 0) {
            return;
        }

        const normalizedMode = (typeof mode === 'string' ? mode.trim().toLowerCase() : '');
        let activePane = null;

        if (normalizedMode !== '') {
            activePane = this.panes.find((pane) => {
                return pane.getAttribute('data-behaviour') === normalizedMode;
            }) || null;
        }

        if (!activePane) {
            [activePane] = this.panes;
        }

        this.panes.forEach((pane) => {
            const isActive = (pane === activePane);
            pane.classList.toggle(this.hiddenClass, !isActive);
            pane.setAttribute('aria-hidden', (isActive ? 'false' : 'true'));
        });
    }
}

window.ExoAiSetup = new ExoAiSetup();
