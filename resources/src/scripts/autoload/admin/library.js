class ExoAuthorLibrary {

    constructor() {
        this.rootSelector = '[data-exmoau-library]';
        this.hiddenClass = 'exmoau-is-hidden';
        this.noticeBaseClass = 'exmoau-library__notice notice';
        this.loaderActiveClass = 'is-active';
        this.namePattern = /^[A-Za-z0-9_.\- ]+$/;

        this.state = {
            view: 'categories',
            category: null,
            page: 1,
        };

        this.pendingCategoryHighlight = null;
        this.isInitialized = false;

        this.handleCategoryDoubleClick = this.handleCategoryDoubleClick.bind(this);
        this.handleFileDoubleClick = this.handleFileDoubleClick.bind(this);
        this.handleActionClick = this.handleActionClick.bind(this);
        this.handlePaginationClick = this.handlePaginationClick.bind(this);
        this.handleBackClick = this.handleBackClick.bind(this);
        this.handleModalClose = this.handleModalClose.bind(this);
        this.handleModalBackdrop = this.handleModalBackdrop.bind(this);
        this.handleKeyUp = this.handleKeyUp.bind(this);
        this.handleTriggerKeyDown = this.handleTriggerKeyDown.bind(this);
        this.handleUploadClick = this.handleUploadClick.bind(this);
        this.handleUploadChange = this.handleUploadChange.bind(this);
    }

    init() {
        if (this.isInitialized) {
            return;
        }

        this.root = document.querySelector(this.rootSelector);

        if (!this.root) {
            return;
        }

        this.isInitialized = true;
        this.configure();
        this.cacheElements();
        this.bindEvents();
        this.loadCategories();
    }

    configure() {
        const dataset = this.root.dataset || {};

        this.ajaxUrl = dataset.libraryAjaxUrl || this.getGlobalAjaxUrl();
        this.nonce = dataset.libraryNonce || '';

        this.actions = this.safeJsonParse(dataset.libraryActions, {});
        this.limits = this.safeJsonParse(dataset.libraryLimits, {});

        if (!this.ajaxUrl) {
            this.setStatus('Unable to determine AJAX endpoint.', { isError: true });
        }
    }

    cacheElements() {
        this.statusNode = this.root.querySelector('[data-library-status]');
        this.statusMessageNode = this.root.querySelector('[data-library-status-message]');
        this.spinnerNode = this.root.querySelector('[data-library-status-spinner]');
        this.noticeNode = this.root.querySelector('[data-library-notice]');
        this.uploadButton = this.root.querySelector('[data-library-upload]');
        this.uploadInput = this.root.querySelector('[data-library-upload-input]');

        this.categoriesPane = this.root.querySelector('[data-library-categories-pane]');
        this.categoriesList = this.root.querySelector('[data-library-categories]');
        this.categoriesEmpty = this.root.querySelector('[data-library-categories-empty]');
        this.categoriesLoader = this.root.querySelector('[data-library-loader="categories"]');

        this.filesPane = this.root.querySelector('[data-library-files-pane]');
        this.filesList = this.root.querySelector('[data-library-files]');
        this.filesEmpty = this.root.querySelector('[data-library-files-empty]');
        this.filesTitle = this.root.querySelector('[data-library-files-title]');
        this.pagination = this.root.querySelector('[data-library-pagination]');
        this.filesLoader = this.root.querySelector('[data-library-loader="files"]');

        this.backButton = this.root.querySelector('[data-library-back]');

        this.modal = this.root.querySelector('[data-library-modal]');
        this.modalTitle = this.root.querySelector('[data-library-modal-title]');
        this.modalContent = this.root.querySelector('[data-library-modal-content]');
        this.modalBody = this.root.querySelector('.exmoau-library__modal-body');
        this.modalLoader = this.root.querySelector('[data-library-modal-loader]');
        this.modalClose = this.root.querySelector('[data-library-modal-close]');
    }

    bindEvents() {
        if (this.categoriesList) {
            this.categoriesList.addEventListener('dblclick', this.handleCategoryDoubleClick);
            this.categoriesList.addEventListener('click', this.handleActionClick);
        }

        if (this.filesList) {
            this.filesList.addEventListener('dblclick', this.handleFileDoubleClick);
            this.filesList.addEventListener('click', this.handleActionClick);
        }

        if (this.pagination) {
            this.pagination.addEventListener('click', this.handlePaginationClick);
        }

        if (this.backButton) {
            this.backButton.addEventListener('click', this.handleBackClick);
        }

        if (this.uploadButton) {
            this.uploadButton.addEventListener('click', this.handleUploadClick);
        }

        if (this.uploadInput) {
            this.uploadInput.addEventListener('change', this.handleUploadChange);
        }

        if (this.modalClose) {
            this.modalClose.addEventListener('click', this.handleModalClose);
        }

        if (this.modal) {
            this.modal.addEventListener('click', this.handleModalBackdrop);
        }

        this.root.addEventListener('keydown', this.handleTriggerKeyDown);
    }

    safeJsonParse(value, fallback) {
        if (!value) {
            return fallback;
        }

        try {
            return JSON.parse(value);
        } catch (error) {
            return fallback;
        }
    }

    getGlobalAjaxUrl() {
        if (
            window.ExMomentAuthorAdminConfig &&
            window.ExMomentAuthorAdminConfig.scripts &&
            window.ExMomentAuthorAdminConfig.scripts.ajaxUrl
        ) {
            return window.ExMomentAuthorAdminConfig.scripts.ajaxUrl;
        }

        return '';
    }

    setStatus(message, options = {}) {
        if (!this.statusNode) {
            return;
        }

        const settings = Object.assign({
            isError: false,
            isLoading: false,
        }, options || {});

        if (this.statusMessageNode) {
            this.statusMessageNode.textContent = message || '';
        } else {
            this.statusNode.textContent = message || '';
        }

        if (this.spinnerNode) {
            this.spinnerNode.classList.toggle('is-active', Boolean(settings.isLoading));
        }

        this.statusNode.classList.toggle('exmoau-library__status--error', Boolean(settings.isError));
    }

    showNotice(message, type = 'success') {
        if (!this.noticeNode) {
            return;
        }

        const variant = type === 'error' ? 'notice-error' : 'notice-success';
        this.noticeNode.className = `${this.noticeBaseClass} ${variant}`;
        this.noticeNode.textContent = message || '';
        this.noticeNode.classList.remove(this.hiddenClass);
        this.noticeNode.setAttribute('role', type === 'error' ? 'alert' : 'status');
    }

    clearNotice() {
        if (!this.noticeNode) {
            return;
        }

        this.noticeNode.className = `${this.noticeBaseClass} ${this.hiddenClass}`;
        this.noticeNode.textContent = '';
        this.noticeNode.removeAttribute('role');
    }

    loadCategories() {
        this.setStatus('Loading categories…', { isLoading: true });
        this.setState({ view: 'categories', category: null, page: 1 });
        this.togglePane(this.categoriesPane, true);
        this.togglePane(this.filesPane, false);
        this.toggleBackButton(false);
        this.renderCategoryList([], { skipEmptyMessage: true });
        this.showLoader(this.categoriesLoader);
        this.setPaneBusy(this.categoriesPane, true);

        this.request(this.actions.listCategories, {})
            .then((response) => {
                if (!response || !response.success) {
                    const errorMessage = this.resolveErrorMessage(response);
                    this.setStatus(errorMessage, { isError: true });

                    return;
                }

                const categories = (response.data && response.data.categories) ? response.data.categories : [];
                this.renderCategoryList(categories);
                if (categories.length === 0) {
                    this.setStatus('No categories available.');
                } else {
                    this.setStatus('Categories loaded.');
                }
            })
            .catch((error) => {
                this.setStatus(error.message || 'Unable to load categories.', { isError: true });
            })
            .finally(() => {
                this.hideLoader(this.categoriesLoader);
                this.setPaneBusy(this.categoriesPane, false);
            });
    }

    renderCategoryList(categories, options = {}) {
        if (!this.categoriesList) {
            return;
        }

        const skipEmptyMessage = Boolean(options.skipEmptyMessage);

        this.categoriesList.innerHTML = '';

        if (!Array.isArray(categories) || categories.length === 0) {
            this.toggleElement(this.categoriesEmpty, !skipEmptyMessage);

            return;
        }

        this.toggleElement(this.categoriesEmpty, false);

        const highlight = this.pendingCategoryHighlight;

        categories.forEach((category) => {
            if (!category || !category.name) {
                return;
            }

            const item = this.createListItem('category', category.name);

            if (highlight && category.name === highlight) {
                item.classList.add('exmoau-library__item--highlight');
            }

            this.categoriesList.appendChild(item);
        });

        if (highlight) {
            this.pendingCategoryHighlight = null;
        }
    }

    handleCategoryDoubleClick(event) {
        const target = event.target;
        const item = this.resolveItemElement(target);

        if (!item) {
            return;
        }

        const name = item.getAttribute('data-library-item-name');

        if (name) {
            this.openCategory(name);
        }
    }

    handleFileDoubleClick(event) {
        const target = event.target;
        const item = this.resolveItemElement(target);

        if (!item) {
            return;
        }

        const name = item.getAttribute('data-library-item-name');

        if (name) {
            this.previewFile(name);
        }
    }

    handleActionClick(event) {
        const control = event.target.closest('[data-library-action]');

        if (!control) {
            return;
        }

        event.preventDefault();

        const action = control.getAttribute('data-library-action');
        const item = control.closest('[data-library-item-name]');

        if (!item) {
            return;
        }

        const type = item.getAttribute('data-library-item-type');
        const name = item.getAttribute('data-library-item-name');

        if (!name || !type) {
            return;
        }

        if (action === 'open') {
            if (type === 'category') {
                this.openCategory(name);
            } else {
                this.previewFile(name);
            }

            return;
        }

        if (action === 'rename') {
            this.renameItem(type, name);

            return;
        }

        if (action === 'delete') {
            this.deleteItem(type, name);
        }
    }

    handlePaginationClick(event) {
        const control = event.target.closest('[data-library-page]');

        if (!control) {
            return;
        }

        event.preventDefault();

        const page = parseInt(control.getAttribute('data-library-page'), 10);

        if (!page || page === this.state.page) {
            return;
        }

        if (!this.state.category) {
            return;
        }

        this.openCategory(this.state.category, page);
    }

    handleBackClick(event) {
        event.preventDefault();
        this.loadCategories();
    }

    handleUploadClick(event) {
        event.preventDefault();

        if (!this.uploadInput || (this.uploadButton && this.uploadButton.disabled)) {
            return;
        }

        this.uploadInput.value = '';
        this.uploadInput.click();
    }

    handleUploadChange(event) {
        const input = event.target;

        if (!input || !input.files || input.files.length === 0) {
            return;
        }

        const file = input.files[0];
        input.value = '';

        this.startUpload(file);
    }

    startUpload(file) {
        if (!file) {
            return;
        }

        this.clearNotice();

        if (!this.actions || !this.actions.uploadLibrary) {
            const configurationMessage = 'Upload rejected: uploader is not configured.';
            this.showNotice(configurationMessage, 'error');
            this.setStatus(configurationMessage, { isError: true });

            return;
        }

        const name = (file.name || '').toLowerCase();

        if (!name.endsWith('.zip')) {
            const typeMessage = 'Upload rejected: only .zip archives are supported.';
            this.showNotice(typeMessage, 'error');
            this.setStatus(typeMessage, { isError: true });

            return;
        }

        const limit = this.resolveUploadLimit();

        if (limit > 0 && file.size > limit) {
            const sizeMessage = 'Upload rejected: archive exceeds the 10 MB limit.';
            this.showNotice(sizeMessage, 'error');
            this.setStatus(sizeMessage, { isError: true });

            return;
        }

        this.setUploadBusy(true);
        this.setStatus('Uploading archive…', { isLoading: true });

        this.uploadArchive(file)
            .then((response) => {
                if (!response || !response.success) {
                    const message = this.resolveErrorMessage(response);
                    this.showNotice(message, 'error');
                    this.setStatus(message, { isError: true });

                    return;
                }

                const payload = response.data || {};
                const category = payload.category || '';
                const message = payload.message || 'Library imported successfully. The new category is now available.';

                this.showNotice(message, 'success');

                if (category) {
                    this.pendingCategoryHighlight = category;
                }

                this.setStatus('Refreshing categories…', { isLoading: true });
                this.loadCategories();
            })
            .catch((error) => {
                const message = error && error.message ? error.message : 'Unable to upload archive.';
                this.showNotice(message, 'error');
                this.setStatus(message, { isError: true });
            })
            .finally(() => {
                this.setUploadBusy(false);
            });
    }

    openCategory(name, page = 1) {
        if (!name) {
            return;
        }

        this.setStatus('Loading files…', { isLoading: true });
        this.setState({ view: 'files', category: name, page });
        this.toggleBackButton(true);
        this.togglePane(this.categoriesPane, false);
        this.togglePane(this.filesPane, true);

        if (this.filesTitle) {
            this.filesTitle.textContent = name;
        }

        this.renderFileList([], { skipEmptyMessage: true });
        this.renderPagination({});
        this.showLoader(this.filesLoader);
        this.setPaneBusy(this.filesPane, true);

        this.request(this.actions.listFiles, {
            category: name,
            page,
            per_page: this.resolvePerPage(),
        })
            .then((response) => {
                if (!response || !response.success) {
                    const errorMessage = this.resolveErrorMessage(response);
                    this.setStatus(errorMessage, { isError: true });

                    return;
                }

                const payload = response.data || {};
                const files = payload.files || [];
                this.setState({
                    category: payload.category || name,
                    page: payload.page || page,
                });

                this.renderFileList(files);
                this.renderPagination(payload);

                if (!files || files.length === 0) {
                    this.setStatus('No files found in this category.');
                } else {
                    this.setStatus('Files loaded.');
                }
            })
            .catch((error) => {
                this.setStatus(error.message || 'Unable to load files.', { isError: true });
            })
            .finally(() => {
                this.hideLoader(this.filesLoader);
                this.setPaneBusy(this.filesPane, false);
            });
    }

    renderFileList(files, options = {}) {
        if (!this.filesList) {
            return;
        }

        const skipEmptyMessage = Boolean(options.skipEmptyMessage);

        this.filesList.innerHTML = '';

        if (!Array.isArray(files) || files.length === 0) {
            this.toggleElement(this.filesEmpty, !skipEmptyMessage);

            return;
        }

        this.toggleElement(this.filesEmpty, false);

        files.forEach((file) => {
            if (!file || !file.name) {
                return;
            }

            const meta = {
                size: file.size,
                modified: file.modified,
            };

            const item = this.createListItem('file', file.name, meta);
            this.filesList.appendChild(item);
        });
    }

    renderPagination(payload) {
        if (!this.pagination) {
            return;
        }

        this.pagination.innerHTML = '';

        const page = payload.page || 1;
        const totalPages = payload.total_pages || 1;
        const total = payload.total || 0;

        const summary = document.createElement('span');
        summary.className = 'exmoau-library__pagination-summary';
        summary.textContent = `Page ${page} of ${totalPages}`;
        this.pagination.appendChild(summary);

        if (total <= this.resolvePerPage() && totalPages <= 1) {
            return;
        }

        if (page > 1) {
            const previous = document.createElement('button');
            previous.type = 'button';
            previous.className = 'button button-secondary exmoau-library__pagination-button';
            previous.setAttribute('data-library-page', String(page - 1));
            previous.textContent = 'Previous';
            this.pagination.appendChild(previous);
        }

        if (page < totalPages) {
            const next = document.createElement('button');
            next.type = 'button';
            next.className = 'button button-secondary exmoau-library__pagination-button';
            next.setAttribute('data-library-page', String(page + 1));
            next.textContent = 'Next';
            this.pagination.appendChild(next);
        }
    }

    previewFile(name) {
        if (!this.state.category || !name) {
            return;
        }

        this.setStatus('Loading preview…', { isLoading: true });
        this.showModal(name, '', { showLoader: true });

        this.request(this.actions.previewFile, {
            category: this.state.category,
            filename: name,
        })
            .then((response) => {
                if (!response || !response.success) {
                    const errorMessage = this.resolveErrorMessage(response);

                    this.setModalTitle(name);
                    this.setModalContent(errorMessage);
                    this.setStatus(errorMessage, { isError: true });

                    return;
                }

                const payload = response.data || {};
                const title = `${payload.filename || name}`;
                const content = payload.content || '';

                this.showModal(title, content);
                this.setStatus('Preview loaded.');
            })
            .catch((error) => {
                this.setStatus(error.message || 'Unable to load preview.', { isError: true });
                this.setModalTitle(title);
                this.setModalContent(content);
                this.setStatus('Preview loaded.', false);
            })
            .finally(() => {
                this.toggleModalLoader(false);
            });
    }

    renameItem(type, name) {
        if (!type || !name) {
            return;
        }

        const promptLabel = (type === 'category') ? 'Enter a new category name' : 'Enter a new file name';
        const nextName = window.prompt(`${promptLabel}:`, name);

        if (nextName === null) {
            return;
        }

        const trimmed = nextName.trim();

        if (!trimmed) {
            window.alert('Name cannot be empty.');
            return;
        }

        if (!this.namePattern.test(trimmed) || trimmed.indexOf('..') !== -1 || trimmed.indexOf('/') !== -1) {
            window.alert('Names may include letters, numbers, spaces, dots, underscores, and dashes only.');
            return;
        }

        const payload = {
            item_type: type,
            current_name: name,
            new_name: trimmed,
        };

        if (type === 'file' && this.state.category) {
            payload.category = this.state.category;
        }

        this.setStatus('Renaming item…', { isLoading: true });

        this.request(this.actions.renameItem, payload)
            .then((response) => {
                if (!response || !response.success) {
                    const errorMessage = this.resolveErrorMessage(response);
                    this.setStatus(errorMessage, { isError: true });

                    return;
                }

                this.setStatus('Item renamed successfully.');

                if (type === 'category') {
                    const renamedTo = (response.data && response.data.new_name) ? response.data.new_name : trimmed;
                    const wasViewingCategory = (this.state.category === name);

                    this.loadCategories();

                    if (wasViewingCategory) {
                        this.openCategory(renamedTo);
                    }
                } else if (this.state.category) {
                    this.openCategory(this.state.category, this.state.page);
                }
            })
            .catch((error) => {
                this.setStatus(error.message || 'Unable to rename item.', { isError: true });
            });
    }

    deleteItem(type, name) {
        if (!type || !name) {
            return;
        }

        const confirmationLabel = (type === 'category')
            ? 'Delete this category and all of its files?'
            : 'Delete this file?';

        if (!window.confirm(confirmationLabel)) {
            return;
        }

        const payload = {
            item_type: type,
            name,
        };

        if (type === 'file' && this.state.category) {
            payload.category = this.state.category;
        }

        this.setStatus('Deleting…', { isLoading: true });

        this.request(this.actions.deleteItem, payload)
            .then((response) => {
                if (!response || !response.success) {
                    const errorMessage = this.resolveErrorMessage(response);
                    this.setStatus(errorMessage, { isError: true });

                    return;
                }

                this.setStatus('Item deleted.');

                if (type === 'category') {
                    this.loadCategories();
                } else if (this.state.category) {
                    this.openCategory(this.state.category, this.state.page);
                }
            })
            .catch((error) => {
                this.setStatus(error.message || 'Unable to delete item.', { isError: true });
            });
    }

    uploadArchive(file) {
        if (!this.ajaxUrl || !this.actions || !this.actions.uploadLibrary) {
            return Promise.reject(new Error('Upload rejected: uploader is not configured.'));
        }

        const formData = new FormData();
        formData.append('action', this.actions.uploadLibrary);
        formData.append('nonce', this.nonce);
        formData.append('library_archive', file);

        return fetch(this.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('Unexpected server response.');
                }

                return response.json();
            });
    }

    request(action, payload) {
        if (!this.ajaxUrl || !action) {
            return Promise.reject(new Error('AJAX configuration missing.'));
        }

        const formData = new URLSearchParams();
        formData.append('action', action);
        formData.append('nonce', this.nonce);

        Object.keys(payload || {}).forEach((key) => {
            if (typeof payload[key] !== 'undefined' && payload[key] !== null) {
                formData.append(key, payload[key]);
            }
        });

        return fetch(this.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            },
            body: formData.toString(),
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('Unexpected server response.');
                }

                return response.json();
            });
    }

    createListItem(type, name, meta = {}) {
        const item = document.createElement('li');
        item.className = 'exmoau-library__item';
        item.setAttribute('data-library-item-type', type);
        item.setAttribute('data-library-item-name', name);

        const trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'exmoau-library__item-trigger';
        trigger.setAttribute('data-library-trigger', type);
        trigger.textContent = name;
        item.appendChild(trigger);

        if (type === 'file') {
            const metaBlock = document.createElement('div');
            metaBlock.className = 'exmoau-library__item-meta';

            const parts = [];
            const size = this.formatBytes(meta.size);
            const modified = this.formatTimestamp(meta.modified);

            if (size) {
                parts.push(size);
            }

            if (modified) {
                parts.push(modified);
            }

            metaBlock.textContent = parts.join(' • ');
            item.appendChild(metaBlock);
        }

        const actions = document.createElement('div');
        actions.className = 'exmoau-library__item-actions';

        ['open', 'rename', 'delete'].forEach((action) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'button button-secondary exmoau-library__action-button';
            button.setAttribute('data-library-action', action);

            if (action === 'open') {
                button.textContent = 'List';
            } else if (action === 'rename') {
                button.textContent = 'Rename';
            } else {
                button.textContent = 'Delete';
            }

            actions.appendChild(button);
        });

        item.appendChild(actions);

        return item;
    }

    resolveItemElement(target) {
        if (!target) {
            return null;
        }

        if (target.matches('[data-library-item-name]')) {
            return target;
        }

        return target.closest('[data-library-item-name]');
    }

    handleTriggerKeyDown(event) {
        const target = event.target;

        if (!target || !target.matches('[data-library-trigger]')) {
            return;
        }

        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }

        event.preventDefault();

        const item = this.resolveItemElement(target);

        if (!item) {
            return;
        }

        const type = item.getAttribute('data-library-item-type');
        const name = item.getAttribute('data-library-item-name');

        if (!name || !type) {
            return;
        }

        if (type === 'category') {
            this.openCategory(name);
        } else {
            this.previewFile(name);
        }
    }

    togglePane(element, isVisible) {
        if (!element) {
            return;
        }

        element.classList.toggle(this.hiddenClass, !isVisible);
    }

    toggleElement(element, isVisible) {
        if (!element) {
            return;
        }

        element.classList.toggle(this.hiddenClass, !isVisible);
    }

    showLoader(loader) {
        this.toggleLoaderElement(loader, true);
    }

    hideLoader(loader) {
        this.toggleLoaderElement(loader, false);
    }

    toggleLoaderElement(loader, isVisible) {
        if (!loader) {
            return;
        }

        const shouldShow = Boolean(isVisible);
        loader.classList.toggle(this.loaderActiveClass, shouldShow);
        loader.setAttribute('aria-hidden', shouldShow ? 'false' : 'true');
    }

    setPaneBusy(element, isBusy) {
        if (!element) {
            return;
        }

        if (isBusy) {
            element.setAttribute('aria-busy', 'true');
        } else {
            element.removeAttribute('aria-busy');
        }
    }

    toggleBackButton(isEnabled) {
        if (!this.backButton) {
            return;
        }

        this.backButton.disabled = !isEnabled;
    }

    setUploadBusy(isBusy) {
        if (this.uploadButton) {
            this.uploadButton.disabled = Boolean(isBusy);
            this.uploadButton.setAttribute('aria-busy', isBusy ? 'true' : 'false');
        }

        if (this.uploadInput) {
            this.uploadInput.disabled = Boolean(isBusy);
        }
    }

    setState(newState) {
        this.state = Object.assign({}, this.state, newState || {});
    }

    resolvePerPage() {
        const limit = parseInt(this.limits.perPage, 10);

        if (!limit || limit < 1) {
            return 50;
        }

        return Math.min(limit, 50);
    }

    resolveUploadLimit() {
        const limit = parseInt(this.limits.upload, 10);

        if (!Number.isFinite(limit) || limit <= 0) {
            return 0;
        }

        return limit;
    }

    formatBytes(bytes) {
        const size = parseInt(bytes, 10);

        if (!size || size <= 0) {
            return '0 B';
        }

        const units = ['B', 'KB', 'MB', 'GB'];
        let index = 0;
        let value = size;

        while (value >= 1024 && index < units.length - 1) {
            value /= 1024;
            index += 1;
        }

        return `${value.toFixed(index === 0 ? 0 : 1)} ${units[index]}`;
    }

    formatTimestamp(timestamp) {
        const value = parseInt(timestamp, 10);

        if (!value) {
            return '';
        }

        const date = new Date(value * 1000);

        return date.toLocaleString();
    }

    resolveErrorMessage(response) {
        if (!response) {
            return 'Unexpected error.';
        }

        if (response.data && response.data.message) {
            return response.data.message;
        }

        if (response.message) {
            return response.message;
        }

        return 'Unexpected error.';
    }

    setModalTitle(title) {
        if (!this.modalTitle) {
            return;
        }

        this.modalTitle.textContent = title || '';
    }

    setModalContent(content) {
        if (!this.modalContent) {
            return;
        }

        this.modalContent.textContent = content || '';
    }

    toggleModalLoader(isVisible) {
        const loader = this.modalLoader;
        const body = this.modalBody;
        const shouldShow = Boolean(isVisible);

        this.toggleLoaderElement(loader, shouldShow);

        if (this.modalContent) {
            this.modalContent.setAttribute('aria-hidden', shouldShow ? 'true' : 'false');
        }

        if (!body) {
            return;
        }

        if (shouldShow) {
            body.setAttribute('aria-busy', 'true');
        } else {
            body.removeAttribute('aria-busy');
        }
    }

    showModal(title, content, options = {}) {
        if (!this.modal || !this.modalContent || !this.modalTitle) {
            return;
        }

        this.setModalTitle(title);
        this.setModalContent(content);
        this.toggleModalLoader(Boolean(options.showLoader));

        this.modal.classList.remove(this.hiddenClass);
        document.addEventListener('keyup', this.handleKeyUp);
    }

    handleModalClose(event) {
        event.preventDefault();
        this.hideModal();
    }

    handleModalBackdrop(event) {
        if (event.target === this.modal) {
            this.hideModal();
        }
    }

    handleKeyUp(event) {
        if (event.key === 'Escape') {
            this.hideModal();
        }
    }

    hideModal() {
        if (!this.modal) {
            return;
        }

        this.modal.classList.add(this.hiddenClass);
        this.toggleModalLoader(false);
        this.setModalContent('');
        this.setModalTitle('');
        document.removeEventListener('keyup', this.handleKeyUp);
    }
}

window.ExoAuthorLibrary = new ExoAuthorLibrary();
