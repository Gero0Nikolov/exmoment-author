window.ExoLibraryWelcomeModal = {

    storageKey: 'exmoau_library_welcome_dismissed',
    legacyStorageKey: 'exmoau_library_welcome_dismissed',

    init: function () {
        const config = (window.ExMomentAuthorAdminConfig || {});
        const libraryConfig = (config.library || {});

        if (libraryConfig.hasContent !== false) {
            return;
        }

        if (this.isDismissed()) {
            return;
        }

        this.render(libraryConfig);
    },

    isDismissed: function () {
        try {
            if (sessionStorage.getItem(this.storageKey) === '1') {
                return true;
            }

            if (sessionStorage.getItem(this.legacyStorageKey) === '1') {
                sessionStorage.setItem(this.storageKey, '1');
                sessionStorage.removeItem(this.legacyStorageKey);
                return true;
            }
        } catch (error) {
            return false;
        }

        return false;
    },

    setDismissed: function () {
        try {
            sessionStorage.setItem(this.storageKey, '1');
        } catch (error) {
            return;
        }
    },

    validateUrl: function (url) {
        if (typeof url !== 'string') {
            return '';
        }

        const trimmed = url.trim();
        if (trimmed === '') {
            return '';
        }

        try {
            const parsed = new URL(trimmed);
            if (parsed.protocol === 'http:' || parsed.protocol === 'https:') {
                return trimmed;
            }
        } catch (error) {
            return '';
        }

        return '';
    },

    render: function (libraryConfig) {
        const welcomeUrl = this.validateUrl(libraryConfig.welcomeCtaUrl || '');
        const libraryAdminUrl = this.validateUrl(libraryConfig.libraryAdminUrl || '');
        const previousFocus = document.activeElement;

        const overlay = document.createElement('div');
        overlay.className = 'exmoau-welcome-overlay';

        const modal = document.createElement('div');
        modal.className = 'exmoau-welcome-modal';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'exmoau-welcome-modal-title');

        const closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'exmoau-welcome-modal__close';
        closeButton.setAttribute('aria-label', 'Close welcome message');
        closeButton.innerHTML = '&times;';

        const title = document.createElement('h2');
        title.className = 'exmoau-welcome-modal__title';
        title.id = 'exmoau-welcome-modal-title';
        title.textContent = 'Welcome — your AI writing studio is ready!';

        const body = document.createElement('div');
        body.className = 'exmoau-welcome-modal__body';

        const bodyText = document.createElement('p');
        bodyText.textContent = 'ExoAuthor includes a free starter batch of ready-to-publish articles.';

        const bodyNote = document.createElement('p');
        bodyNote.textContent = (
            'To deliver it, we only ask for an email address on the download page. WordPress.org does not allow ' +
            'distributing plugins with pre-bundled article content, so we provide the starter batch externally.'
        );

        const bodyIntro = document.createElement('p');
        bodyIntro.className = 'exmoau-welcome-modal__intro';
        bodyIntro.textContent = 'Get your free starter batch in under a minute:';

        const list = document.createElement('ul');
        list.className = 'exmoau-welcome-modal__list';

        const listItems = [
            'Open the download page and enter your email',
            'Download the Library pack',
            'Place it in your ExoAuthor Library folder, then refresh wp-admin',
        ];

        listItems.forEach((text) => {
            const item = document.createElement('li');
            item.textContent = text;
            list.appendChild(item);
        });

        const footer = document.createElement('p');
        footer.className = 'exmoau-welcome-modal__footer';
        footer.textContent = 'Already have Library content? You can close this window and keep working.';

        body.appendChild(bodyText);
        body.appendChild(bodyNote);
        body.appendChild(bodyIntro);
        body.appendChild(list);
        body.appendChild(footer);

        const actions = document.createElement('div');
        actions.className = 'exmoau-welcome-modal__actions';

        let ctaButton = null;
        if (welcomeUrl !== '') {
            ctaButton = document.createElement('a');
            ctaButton.className = 'button button-primary exmoau-welcome-modal__cta';
            ctaButton.href = welcomeUrl;
            ctaButton.target = '_blank';
            ctaButton.rel = 'noopener noreferrer';
            ctaButton.textContent = 'Get Free Content Pack';
            actions.appendChild(ctaButton);
        }

        if (libraryAdminUrl !== '') {
            const uploadButton = document.createElement('a');
            uploadButton.className = 'button button-secondary exmoau-welcome-modal__cta exmoau-welcome-modal__cta--secondary';
            uploadButton.href = libraryAdminUrl;
            uploadButton.textContent = 'Upload Content';
            actions.appendChild(uploadButton);
        }

        modal.appendChild(closeButton);
        modal.appendChild(title);
        modal.appendChild(body);
        modal.appendChild(actions);
        overlay.appendChild(modal);
        document.body.appendChild(overlay);

        document.body.classList.add('exmoau-welcome-modal-open');

        const focusableSelector = [
            'a[href]',
            'button:not([disabled])',
            'input:not([disabled])',
            'select:not([disabled])',
            'textarea:not([disabled])',
            '[tabindex]:not([tabindex=\"-1\"])',
        ].join(',');

        const closeModal = () => {
            this.setDismissed();
            overlay.classList.add('exmoau-is-hidden');
            document.body.classList.remove('exmoau-welcome-modal-open');
            document.removeEventListener('keydown', handleEscape);
            modal.removeEventListener('keydown', handleTrap);

            if (previousFocus && typeof previousFocus.focus === 'function') {
                previousFocus.focus();
            }
        };

        const handleEscape = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeModal();
            }
        };

        const handleTrap = (event) => {
            if (event.key !== 'Tab') {
                return;
            }

            const focusableItems = modal.querySelectorAll(focusableSelector);
            if (focusableItems.length === 0) {
                event.preventDefault();
                return;
            }

            const firstItem = focusableItems[0];
            const lastItem = focusableItems[focusableItems.length - 1];

            if (event.shiftKey && document.activeElement === firstItem) {
                event.preventDefault();
                lastItem.focus();
                return;
            }

            if (!event.shiftKey && document.activeElement === lastItem) {
                event.preventDefault();
                firstItem.focus();
            }
        };

        closeButton.addEventListener('click', closeModal);
        document.addEventListener('keydown', handleEscape);
        modal.addEventListener('keydown', handleTrap);

        const initialFocus = (ctaButton || closeButton);
        if (initialFocus && typeof initialFocus.focus === 'function') {
            initialFocus.focus();
        }
    }
};
