class ExoLog {
    constructor(doc = document, win = window) {
        this.document = doc;
        this.window = win;
        this.detailSelector = '.exo-log__detail';
        this.detailHash = '#exo-log-detail';
        this.detailId = 'exo-log-detail';
        this.focusableSelector = 'h2, h3, a, button, input, textarea, select';
        this.detailElement = null;
        this.isInitialized = false;
    }

    init() {
        if (this.isInitialized) {
            return;
        }

        const detail = this.document.querySelector(this.detailSelector);

        if (!detail) {
            return;
        }

        this.detailElement = detail;
        this.ensureDetailElement(detail);

        this.isInitialized = true;

        if (this.window.location.hash === this.detailHash) {
            this.window.requestAnimationFrame(() => {
                this.focusDetail(detail);
            });
        }
    }

    ensureDetailElement(detail) {
        if (!detail.id) {
            detail.id = this.detailId;
        }

        if (!detail.hasAttribute('tabindex')) {
            detail.setAttribute('tabindex', '-1');
        }
    }

    focusDetail(detail = this.detailElement) {
        if (!detail) {
            return;
        }

        const focusTarget = detail.querySelector(this.focusableSelector);
        const target = focusTarget || detail;

        if (target === detail && !detail.hasAttribute('tabindex')) {
            detail.setAttribute('tabindex', '-1');
        }

        if (typeof detail.scrollIntoView === 'function') {
            detail.scrollIntoView({
                behavior: 'smooth',
                block: 'start',
            });
        }

        this.window.setTimeout(() => {
            if (typeof target.focus === 'function') {
                try {
                    target.focus({ preventScroll: true });
                } catch (error) {
                    target.focus();
                }
            }
        }, 10);
    }

}

window.ExoLog = new ExoLog();
