class JobTypeController {
    constructor(container) {
        this.container = container;
        this.hiddenClass = 'exmoau-is-hidden';
        this.typeInputs = Array.from(container.querySelectorAll('input[name="exmoau_job_type"]'));
        this.typePanes = Array.from(container.querySelectorAll('[data-exmoau-job-type-pane]'));
        this.dayCheckboxes = Array.from(container.querySelectorAll('[data-exmoau-job-repeat-day]'));
        this.repeatContainer = container.querySelector('[data-exmoau-job-type-pane="repeating_scheduled"]');
        this.timeListAttribute = 'data-exmoau-job-time-list';
        this.dayPaneAttribute = 'data-exmoau-job-day-pane';
        this.addTimeAttribute = 'data-exmoau-job-add-time';
        this.removeTimeAttribute = 'data-exmoau-job-remove-time';
        this.timeFieldCounter = 0;
    }

    init() {
        if (!this.container) {
            return;
        }

        this.initialiseTimeFieldCounter();
        this.bindTypeInputs();
        this.setupRepeatingDayControls();
        this.updateTypeVisibility();
        this.bindTimeButtons();
    }

    bindTypeInputs() {
        if (!this.typeInputs.length) {
            return;
        }

        this.typeInputs.forEach((input) => {
            input.addEventListener('change', () => {
                this.updateTypeVisibility();
            });
        });
    }

    getActiveType() {
        const active = this.typeInputs.find((input) => input.checked);
        if (active && typeof active.value === 'string') {
            return active.value;
        }

        if (this.typeInputs.length > 0 && typeof this.typeInputs[0].value === 'string') {
            return this.typeInputs[0].value;
        }

        return '';
    }

    updateTypeVisibility() {
        const activeType = this.getActiveType();

        this.typePanes.forEach((pane) => {
            const paneType = pane.getAttribute('data-exmoau-job-type-pane');
            const isActive = paneType === activeType;
            this.togglePane(pane, isActive);
        });
    }

    togglePane(pane, isActive) {
        if (!pane) {
            return;
        }

        pane.classList.toggle(this.hiddenClass, !isActive);
        pane.setAttribute('aria-hidden', isActive ? 'false' : 'true');

        const interactiveElements = pane.querySelectorAll('input, select, textarea, button');
        interactiveElements.forEach((element) => {
            if (element.matches('button')) {
                element.disabled = !isActive;
                return;
            }

            if (element.matches('input, select, textarea')) {
                element.disabled = !isActive;
            }
        });
    }

    setupRepeatingDayControls() {
        if (!this.dayCheckboxes.length) {
            return;
        }

        this.dayCheckboxes.forEach((checkbox) => {
            const dayKey = checkbox.getAttribute('data-exmoau-job-repeat-day');
            this.toggleDayPane(dayKey, checkbox.checked);

            checkbox.addEventListener('change', (event) => {
                const target = event.target;
                if (!(target instanceof window.HTMLInputElement)) {
                    return;
                }

                const key = target.getAttribute('data-exmoau-job-repeat-day');
                this.toggleDayPane(key, target.checked);
            });
        });
    }

    toggleDayPane(dayKey, isActive) {
        if (!dayKey || !this.repeatContainer) {
            return;
        }

        const selector = `[${this.dayPaneAttribute}="${dayKey}"]`;
        const pane = this.repeatContainer.querySelector(selector);
        if (!pane) {
            return;
        }

        pane.classList.toggle(this.hiddenClass, !isActive);
        pane.setAttribute('aria-hidden', isActive ? 'false' : 'true');

        const inputs = pane.querySelectorAll('input');
        inputs.forEach((input) => {
            input.disabled = !isActive;
        });

        const buttons = pane.querySelectorAll('button');
        buttons.forEach((button) => {
            button.disabled = !isActive;
        });
    }

    bindTimeButtons() {
        if (!this.repeatContainer) {
            return;
        }

        this.repeatContainer.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof window.Element)) {
                return;
            }

            const addButton = target.closest(`[${this.addTimeAttribute}]`);
            if (addButton && !addButton.disabled) {
                event.preventDefault();
                const dayKey = addButton.getAttribute(this.addTimeAttribute);
                if (dayKey) {
                    this.addTimeField(dayKey);
                }
                return;
            }

            const removeButton = target.closest(`[${this.removeTimeAttribute}]`);
            if (removeButton && !removeButton.disabled) {
                event.preventDefault();
                this.removeTimeField(removeButton);
            }
        });
    }

    addTimeField(dayKey) {
        if (!dayKey || !this.repeatContainer) {
            return;
        }

        const listSelector = `[${this.timeListAttribute}="${dayKey}"]`;
        const timeList = this.repeatContainer.querySelector(listSelector);
        if (!timeList) {
            return;
        }

        const template = timeList.querySelector('.exmoau-job-type__repeat-time');
        if (!template) {
            return;
        }

        const clone = template.cloneNode(true);
        const input = clone.querySelector('input');
        const label = clone.querySelector('label');

        this.timeFieldCounter += 1;
        const fieldId = `exmoau_job_repeating_hours_${dayKey}_${this.timeFieldCounter}`;

        if (input) {
            input.value = '';
            input.disabled = false;
            input.id = fieldId;
            input.setAttribute('name', `exmoau_job_repeating_hours_by_day[${dayKey}][]`);
        }

        if (label && input) {
            label.setAttribute('for', fieldId);
        }

        const removeButton = clone.querySelector(`[${this.removeTimeAttribute}]`);
        if (removeButton) {
            removeButton.disabled = false;
            removeButton.setAttribute(this.removeTimeAttribute, dayKey);
        }

        timeList.appendChild(clone);
    }

    removeTimeField(button) {
        if (!button) {
            return;
        }

        const wrapper = button.closest('.exmoau-job-type__repeat-time');
        if (!wrapper) {
            return;
        }

        const timeList = wrapper.parentElement;
        if (!timeList) {
            return;
        }

        const remaining = timeList.querySelectorAll('.exmoau-job-type__repeat-time');
        if (remaining.length <= 1) {
            const input = wrapper.querySelector('input');
            if (input) {
                input.value = '';
            }
            return;
        }

        wrapper.remove();
    }

    initialiseTimeFieldCounter() {
        const inputs = this.container.querySelectorAll('input[id^="exmoau_job_repeating_hours_"]');
        let maxIndex = 0;

        inputs.forEach((input) => {
            const match = input.id.match(/_(\d+)$/);
            if (!match || match.length < 2) {
                return;
            }

            const index = parseInt(match[1], 10);
            if (!Number.isNaN(index) && index > maxIndex) {
                maxIndex = index;
            }
        });

        this.timeFieldCounter = maxIndex;
    }
}

class JobSetupController {
    static getDefaultActions() {
        if (!this.defaultActions) {
            this.defaultActions = {
                mixture: 'exmoau_get_mixture_tab',
                directive: 'exmoau_get_directive_tab',
            };
        }

        return this.defaultActions;
    }

    static getKeyCodes() {
        if (!this.keyCodes) {
            this.keyCodes = {
                ENTER: 'Enter',
                SPACE: ' ',
                SPACE_KEY: 'Spacebar',
                ARROW_LEFT: 'ArrowLeft',
                ARROW_RIGHT: 'ArrowRight',
            };
        }

        return this.keyCodes;
    }

    static escapeHtml(value) {
        if (typeof value !== 'string') {
            return '';
        }

        return value.replace(/[&<>'"]/g, (character) => {
            switch (character) {
                case '&':
                    return '&amp;';
                case '<':
                    return '&lt;';
                case '>':
                    return '&gt;';
                case '"':
                    return '&quot;';
                case '\'':
                    return '&#039;';
                default:
                    return character;
            }
        });
    }

    constructor(container) {
        this.container = container;
        this.config = this.parseConfig();
        this.messages = this.config.messages || {};
        this.ajaxActions = this.config.ajaxActions || JobSetupController.getDefaultActions();
        this.postId = parseInt(this.config.postId, 10) || 0;
        this.nonce = this.config.nonce || '';
        this.ajaxUrl = this.getAjaxUrl();

        const initialAuthor = (typeof this.config.postAuthor === 'string') ? this.config.postAuthor : '';
        const initialStatus = (typeof this.config.postStatus === 'string') ? this.config.postStatus : 'draft';
        const initialPerCategory = (typeof this.config.perCategory === 'string') ? this.config.perCategory : '';
        const initialGeneration = (typeof this.config.generationCount === 'string') ? this.config.generationCount : '';

        this.state = {
            directories: new Set(this.config.directories || []),
            uniqueness: this.config.uniqueness === '1',
            perCategory: initialPerCategory,
            directive: this.config.directive || '',
            postStatus: initialStatus || 'draft',
            postAuthor: initialAuthor,
            generationCount: initialGeneration,
            invalidDirectivePostType: this.config.invalidDirectivePostType === '1',
            invalidDirectivePostAuthor: this.config.invalidDirectivePostAuthor === '1',
            activeTab: this.config.activeTab || 'mixture',
        };

        this.loadedTabs = {};
        this.pendingRequests = {};
        this.availableDirectories = new Set(this.state.directories);
        this.currentMixturePage = 1;
        this.totalMixturePages = 1;

        this.form = this.container.closest('form');
        this.tabs = Array.from(this.container.querySelectorAll('[data-exmoau-job-setup-tab]'));
        this.panels = {
            mixture: this.container.querySelector('[data-exmoau-job-setup-pane="mixture"]'),
            directive: this.container.querySelector('[data-exmoau-job-setup-pane="directive"]'),
        };
        this.panelInners = {
            mixture: this.container.querySelector('[data-exmoau-job-setup-panel-inner="mixture"]'),
            directive: this.container.querySelector('[data-exmoau-job-setup-panel-inner="directive"]'),
        };
        this.loadingElement = this.container.querySelector('[data-exmoau-job-setup-loading]');
        this.statusRegion = this.container.querySelector('[data-exmoau-job-setup-status]');
        this.directoriesField = this.container.querySelector('[data-exmoau-job-setup-directories-field]');
        this.directiveField = this.container.querySelector('[data-exmoau-job-setup-directive-field]');
        this.postStatusField = this.container.querySelector('[data-exmoau-job-setup-directive-status-field]');
        this.postAuthorField = this.container.querySelector('[data-exmoau-job-setup-directive-author-field]');
        this.generationField = this.container.querySelector('[data-exmoau-job-setup-directive-generation-field]');
        this.activeTabField = this.container.querySelector('[data-exmoau-job-setup-active-tab]');
        this.mixtureStatusRegion = null;
    }

    parseConfig() {
        const raw = this.container.getAttribute('data-exmoau-job-setup');
        if (!raw) {
            return {};
        }

        try {
            return JSON.parse(raw);
        } catch (error) {
            console.error('Failed to parse job setup configuration.', error);
            return {};
        }
    }

    getAjaxUrl() {
        if (
            window.ExMomentAuthorAdminConfig &&
            window.ExMomentAuthorAdminConfig.scripts &&
            window.ExMomentAuthorAdminConfig.scripts.ajaxUrl
        ) {
            return window.ExMomentAuthorAdminConfig.scripts.ajaxUrl;
        }

        return '';
    }

    init() {
        if (!this.container || !this.ajaxUrl || !this.nonce) {
            return;
        }

        this.updateHiddenDirectiveField(this.state.directive);
        this.updateHiddenPostStatusField(this.state.postStatus);
        this.updateHiddenPostAuthorField(this.state.postAuthor);
        this.updateHiddenDirectoriesFields();
        this.updateActiveTabUI();
        this.bindEvents();
        this.loadInitialContent();
    }

    bindEvents() {
        this.tabs.forEach((tab) => {
            tab.addEventListener('click', (event) => {
                event.preventDefault();
                const tabKey = tab.getAttribute('data-exmoau-job-setup-tab');
                if (tabKey) {
                    this.setActiveTab(tabKey);
                }
            });

            tab.addEventListener('keydown', (event) => {
                this.handleTabKeydown(event);
            });
        });

        if (this.form) {
            this.form.addEventListener('submit', () => {
                this.syncHiddenFields();
            });
        }
    }

    loadInitialContent() {
        const activeTab = this.state.activeTab;
        const initialPage = activeTab === 'mixture' ? this.currentMixturePage : 1;

        this.fetchTab(activeTab, { page: initialPage })
            .then(() => {
                const secondaryTab = activeTab === 'mixture' ? 'directive' : 'mixture';
                const secondaryPage = secondaryTab === 'mixture' ? this.currentMixturePage : 1;
                this.fetchTab(secondaryTab, { page: secondaryPage, silent: true });
            })
            .catch(() => {
                this.displayPanelError(activeTab, this.formatMessage('loadError'));
            });
    }

    fetchTab(tab, options = {}) {
        if (!this.ajaxActions[tab] || !this.ajaxUrl || !this.nonce) {
            return Promise.resolve();
        }

        if (!options.silent) {
            this.showLoading(tab);
        }

        if (this.pendingRequests[tab] && typeof this.pendingRequests[tab].abort === 'function') {
            this.pendingRequests[tab].abort();
        }

        const controller = (typeof window.AbortController !== 'undefined') ? new window.AbortController() : null;
        if (controller) {
            this.pendingRequests[tab] = controller;
        } else {
            delete this.pendingRequests[tab];
        }

        const formData = new window.FormData();
        formData.append('action', this.ajaxActions[tab]);
        formData.append('nonce', this.nonce);
        formData.append('post_id', String(this.postId));

        const pageValue = options.page ? parseInt(options.page, 10) || 1 : (tab === 'mixture' ? this.currentMixturePage : 1);
        formData.append('page', String(pageValue));
        formData.append('uniqueness', this.state.uniqueness ? '1' : '');

        this.state.directories.forEach((directory) => {
            formData.append('selected_directories[]', directory);
        });

        if (tab === 'mixture') {
            formData.append('per_category', this.state.perCategory || '');
        }

        if (tab === 'directive') {
            formData.append('post_status', this.state.postStatus || 'draft');
            formData.append('post_author', this.state.postAuthor || '');
            formData.append('invalid_post_type', this.state.invalidDirectivePostType ? '1' : '');
            formData.append('invalid_post_author', this.state.invalidDirectivePostAuthor ? '1' : '');
            formData.append('generation_count', this.state.generationCount || '');
        }

        if (this.state.directive) {
            formData.append('directive', this.state.directive);
        }

        const fetchOptions = {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
        };

        if (controller) {
            fetchOptions.signal = controller.signal;
        }

        return window.fetch(this.ajaxUrl, fetchOptions)
            .then((response) => {
                if (!response.ok) {
                    throw new Error('request-failed');
                }
                return response.json();
            })
            .then((payload) => {
                if (!payload || !payload.success || !payload.data) {
                    throw new Error('invalid-response');
                }

                const data = payload.data;
                this.loadedTabs[tab] = true;

                if (tab === 'mixture') {
                    this.renderMixturePanel(data);
                } else if (tab === 'directive') {
                    this.renderDirectivePanel(data);
                }
            })
            .catch((error) => {
                if (error && error.name === 'AbortError') {
                    return;
                }
                this.displayPanelError(tab, this.formatMessage('loadError'));
                this.announceStatus(this.formatMessage('loadError'));
            })
            .finally(() => {
                if (!options.silent) {
                    this.hideLoading(tab);
                }

                if (!controller) {
                    delete this.pendingRequests[tab];
                } else if (this.pendingRequests[tab] === controller) {
                    delete this.pendingRequests[tab];
                }
            });
    }

    showLoading(tab) {
        const panel = this.panels[tab];
        if (panel) {
            panel.setAttribute('aria-busy', 'true');
        }

        if (this.loadingElement && tab === this.state.activeTab) {
            this.loadingElement.classList.add('is-active');
            this.loadingElement.setAttribute('aria-hidden', 'false');
        }
    }

    hideLoading(tab) {
        const panel = this.panels[tab];
        if (panel) {
            panel.removeAttribute('aria-busy');
        }

        if (this.loadingElement && tab === this.state.activeTab) {
            this.loadingElement.classList.remove('is-active');
            this.loadingElement.setAttribute('aria-hidden', 'true');
        }
    }

    renderMixturePanel(data) {
        const inner = this.panelInners.mixture;
        if (!inner) {
            return;
        }

        inner.innerHTML = data.html || '';

        if (Array.isArray(data.directories)) {
            this.availableDirectories = new Set(data.directories);
        }

        if (Array.isArray(data.selected)) {
            this.state.directories = new Set(data.selected);
        }

        this.state.uniqueness = !!data.uniqueness;
        if (typeof data.perCategory !== 'undefined' && data.perCategory !== null) {
            this.state.perCategory = String(data.perCategory);
        }
        this.currentMixturePage = data.page ? parseInt(data.page, 10) || 1 : 1;
        this.totalMixturePages = data.totalPages ? parseInt(data.totalPages, 10) || 1 : 1;

        this.bindMixturePanelEvents();
        this.updateMixtureTiles();
        this.updateHiddenDirectoriesFields();
    }

    renderDirectivePanel(data) {
        const inner = this.panelInners.directive;
        if (!inner) {
            return;
        }

        inner.innerHTML = data.html || '';

        if (typeof data.directive === 'string') {
            this.state.directive = data.directive;
        }

        if (typeof data.postStatus === 'string') {
            this.state.postStatus = data.postStatus || 'draft';
        }

        if (typeof data.postAuthor === 'string' || typeof data.postAuthor === 'number') {
            const authorValue = data.postAuthor ? String(data.postAuthor) : '';
            this.state.postAuthor = authorValue;
        }

        if (typeof data.invalidPostType !== 'undefined') {
            this.state.invalidDirectivePostType = !!data.invalidPostType;
        }

        if (typeof data.invalidPostAuthor !== 'undefined') {
            this.state.invalidDirectivePostAuthor = !!data.invalidPostAuthor;
        }

        if (typeof data.generationCount !== 'undefined' && data.generationCount !== null) {
            this.state.generationCount = String(data.generationCount);
        }

        this.bindDirectivePanelEvents();
        this.updateHiddenDirectiveField(this.state.directive);
        this.updateHiddenPostStatusField(this.state.postStatus);
        this.updateHiddenPostAuthorField(this.state.postAuthor);
        this.updateHiddenGenerationField(this.state.generationCount);
    }

    bindMixturePanelEvents() {
        const inner = this.panelInners.mixture;
        if (!inner) {
            return;
        }

        const tiles = inner.querySelectorAll('[data-exmoau-job-mixture-tile]');
        tiles.forEach((tile) => {
            tile.addEventListener('click', (event) => {
                event.preventDefault();
                const directory = tile.getAttribute('data-exmoau-job-mixture-tile');
                if (directory) {
                    const isSelected = this.toggleDirectory(directory);
                    this.setTileState(tile, isSelected);
                }
            });

            tile.addEventListener('keydown', (event) => {
                const keyCodes = JobSetupController.getKeyCodes();

                if (
                    event.key === keyCodes.ENTER ||
                    event.key === keyCodes.SPACE ||
                    event.key === keyCodes.SPACE_KEY
                ) {
                    event.preventDefault();
                    const directory = tile.getAttribute('data-exmoau-job-mixture-tile');
                    if (directory) {
                        const isSelected = this.toggleDirectory(directory);
                        this.setTileState(tile, isSelected);
                    }
                }
            });
        });

        const selectAll = inner.querySelector('[data-exmoau-job-mixture-select="all"]');
        if (selectAll) {
            selectAll.addEventListener('click', (event) => {
                event.preventDefault();
                this.selectAllDirectories();
            });
        }

        const clearAll = inner.querySelector('[data-exmoau-job-mixture-select="none"]');
        if (clearAll) {
            clearAll.addEventListener('click', (event) => {
                event.preventDefault();
                this.clearAllDirectories();
            });
        }

        const paginationButtons = inner.querySelectorAll('[data-exmoau-job-mixture-page]');
        paginationButtons.forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                if (button.disabled) {
                    return;
                }

                const targetPage = parseInt(button.getAttribute('data-exmoau-job-mixture-page'), 10);
                if (!Number.isNaN(targetPage) && targetPage !== this.currentMixturePage) {
                    this.announceStatus(this.formatMessage('pagination', targetPage));
                    this.fetchTab('mixture', { page: targetPage });
                }
            });
        });

        const uniquenessCheckbox = inner.querySelector('input[name="exmoau_setup_mixture_uniqueness"]');
        if (uniquenessCheckbox) {
            uniquenessCheckbox.addEventListener('change', (event) => {
                this.state.uniqueness = event.target.checked;
            });
        }

        const perCategoryInput = inner.querySelector('[data-exmoau-job-mixture-per-category]');
        if (perCategoryInput) {
            if (this.state.perCategory) {
                perCategoryInput.value = this.state.perCategory;
            }
            perCategoryInput.addEventListener('input', () => {
                const value = perCategoryInput.value ? perCategoryInput.value.trim() : '';
                this.state.perCategory = value;
            });
        }

        this.mixtureStatusRegion = inner.querySelector('[data-exmoau-job-mixture-status]');
    }

    bindDirectivePanelEvents() {
        const inner = this.panelInners.directive;
        if (!inner) {
            return;
        }

        const directiveSelect = inner.querySelector('[data-exmoau-job-directive-select]');
        if (directiveSelect) {
            directiveSelect.value = this.state.directive || '';
            directiveSelect.addEventListener('change', (event) => {
                const value = event.target.value || '';
                this.state.directive = value;
                this.state.invalidDirectivePostType = false;
                this.updateHiddenDirectiveField(value);

                if (value) {
                    const selectedOption = event.target.options[event.target.selectedIndex];
                    const label = selectedOption ? selectedOption.text : value;
                    this.announceStatus(this.formatMessage('directiveSet', label));
                } else {
                    this.announceStatus(this.formatMessage('directiveCleared'));
                }
            });
        }

        const statusSelect = inner.querySelector('[data-exmoau-job-directive-status]');
        if (statusSelect) {
            statusSelect.value = this.state.postStatus || 'draft';
            statusSelect.addEventListener('change', (event) => {
                const value = event.target.value === 'publish' ? 'publish' : 'draft';
                this.state.postStatus = value;
                this.updateHiddenPostStatusField(value);

                const selectedOption = event.target.options[event.target.selectedIndex];
                const label = selectedOption ? selectedOption.text : value;
                this.announceStatus(this.formatMessage('statusSet', label));
            });
        }

        const authorSelect = inner.querySelector('[data-exmoau-job-directive-author-select]');
        if (authorSelect) {
            authorSelect.value = this.state.postAuthor || '';
            authorSelect.addEventListener('change', (event) => {
                const value = event.target.value || '';
                this.state.postAuthor = value;
                this.state.invalidDirectivePostAuthor = false;
                this.updateHiddenPostAuthorField(value);

                if (value) {
                    const selectedOption = event.target.options[event.target.selectedIndex];
                    const label = selectedOption ? selectedOption.text : value;
                    this.announceStatus(this.formatMessage('authorSet', label));
                } else {
                    this.announceStatus(this.formatMessage('authorCleared'));
                }
            });
        }

        const generationInput = inner.querySelector('[data-exmoau-job-directive-generation]');
        if (generationInput) {
            generationInput.value = this.state.generationCount || '';
            generationInput.addEventListener('input', () => {
                const value = generationInput.value ? generationInput.value.trim() : '';
                this.state.generationCount = value;
                this.updateHiddenGenerationField(value);
            });
        }
    }

    toggleDirectory(directory) {
        if (this.availableDirectories.size > 0 && !this.availableDirectories.has(directory)) {
            return this.state.directories.has(directory);
        }

        if (this.state.directories.has(directory)) {
            this.state.directories.delete(directory);
            this.announceStatus(this.formatMessage('deselected', directory));
            this.updateHiddenDirectoriesFields();
            return false;
        }

        this.state.directories.add(directory);
        this.announceStatus(this.formatMessage('selected', directory));
        this.updateHiddenDirectoriesFields();
        return true;
    }

    selectAllDirectories() {
        if (this.availableDirectories.size === 0) {
            return;
        }

        this.state.directories = new Set(this.availableDirectories);
        this.updateMixtureTiles();
        this.syncHiddenFields();
        this.announceStatus(this.formatMessage('selectAll'));
    }

    clearAllDirectories() {
        this.state.directories.clear();
        this.updateMixtureTiles();
        this.syncHiddenFields();
        this.announceStatus(this.formatMessage('clearAll'));
    }

    setTileState(tile, isSelected) {
        if (!tile) {
            return;
        }

        tile.classList.toggle('button-primary', isSelected);
        tile.classList.toggle('button-secondary', !isSelected);
        tile.classList.toggle('is-selected', isSelected);
        tile.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
    }

    updateMixtureTiles() {
        const inner = this.panelInners.mixture;
        if (!inner) {
            return;
        }

        const tiles = inner.querySelectorAll('[data-exmoau-job-mixture-tile]');
        tiles.forEach((tile) => {
            const directory = tile.getAttribute('data-exmoau-job-mixture-tile');
            const isSelected = directory ? this.state.directories.has(directory) : false;
            this.setTileState(tile, isSelected);
        });

        const uniquenessCheckbox = inner.querySelector('input[name="exmoau_setup_mixture_uniqueness"]');
        if (uniquenessCheckbox) {
            uniquenessCheckbox.checked = this.state.uniqueness;
        }
    }

    updateHiddenDirectoriesFields() {
        if (!this.directoriesField) {
            return;
        }

        this.directoriesField.innerHTML = '';

        const directories = Array.from(this.state.directories).sort((a, b) => a.localeCompare(b));
        directories.forEach((directory) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'exmoau_setup_mixture_directories[]';
            input.value = directory;
            this.directoriesField.appendChild(input);
        });
    }

    updateHiddenDirectiveField(value) {
        if (!this.directiveField) {
            return;
        }

        this.directiveField.value = value || '';
    }

    updateHiddenPostStatusField(value) {
        if (!this.postStatusField) {
            return;
        }

        const status = value === 'publish' ? 'publish' : 'draft';
        this.postStatusField.value = status;
    }

    updateHiddenPostAuthorField(value) {
        if (!this.postAuthorField) {
            return;
        }

        this.postAuthorField.value = value ? String(value) : '';
    }

    updateHiddenGenerationField(value) {
        if (!this.generationField) {
            return;
        }

        this.generationField.value = value ? String(value) : '';
    }

    syncHiddenFields() {
        this.updateHiddenDirectoriesFields();
        this.updateHiddenDirectiveField(this.state.directive);
        this.updateHiddenPostStatusField(this.state.postStatus);
        this.updateHiddenPostAuthorField(this.state.postAuthor);
        this.updateHiddenGenerationField(this.state.generationCount);
        if (this.activeTabField) {
            this.activeTabField.value = this.state.activeTab;
        }
    }

    setActiveTab(tab) {
        if (!this.panels[tab] || tab === this.state.activeTab) {
            return;
        }

        this.state.activeTab = tab;
        this.updateActiveTabUI();
        this.syncHiddenFields();

        if (!this.loadedTabs[tab]) {
            const pageValue = tab === 'mixture' ? this.currentMixturePage : 1;
            this.fetchTab(tab, { page: pageValue });
        }
    }

    updateActiveTabUI() {
        this.tabs.forEach((tab) => {
            const tabKey = tab.getAttribute('data-exmoau-job-setup-tab');
            const isActive = tabKey === this.state.activeTab;
            tab.classList.toggle('nav-tab-active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
            tab.setAttribute('tabindex', isActive ? '0' : '-1');
        });

        Object.keys(this.panels).forEach((key) => {
            const panel = this.panels[key];
            if (!panel) {
                return;
            }

            const isActive = key === this.state.activeTab;
            panel.classList.toggle('is-active', isActive);
            panel.classList.toggle('exmoau-is-hidden', !isActive);
            panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
        });
    }

    handleTabKeydown(event) {
        const key = event.key;
        const keyCodes = JobSetupController.getKeyCodes();

        if (key !== keyCodes.ARROW_LEFT && key !== keyCodes.ARROW_RIGHT) {
            return;
        }

        event.preventDefault();

        const currentIndex = this.tabs.indexOf(event.currentTarget);
        if (currentIndex === -1) {
            return;
        }

        const increment = key === keyCodes.ARROW_RIGHT ? 1 : -1;
        let targetIndex = currentIndex + increment;

        if (targetIndex < 0) {
            targetIndex = this.tabs.length - 1;
        }

        if (targetIndex >= this.tabs.length) {
            targetIndex = 0;
        }

        const targetTab = this.tabs[targetIndex];
        if (targetTab) {
            targetTab.focus();
            const tabKey = targetTab.getAttribute('data-exmoau-job-setup-tab');
            if (tabKey) {
                this.setActiveTab(tabKey);
            }
        }
    }

    formatMessage(key, value) {
        const template = this.messages[key];
        if (typeof template !== 'string') {
            if (typeof value === 'undefined') {
                return '';
            }
            return String(value);
        }

        if (typeof value === 'undefined') {
            return template;
        }

        const textValue = String(value);
        return template.replace(/%s|%d/g, textValue);
    }

    announceStatus(message) {
        if (!message || typeof message !== 'string') {
            return;
        }

        if (this.statusRegion) {
            this.statusRegion.textContent = message;
        }

        if (this.mixtureStatusRegion) {
            this.mixtureStatusRegion.textContent = message;
        }
    }

    displayPanelError(tab, message) {
        const panelInner = this.panelInners[tab];
        if (!panelInner) {
            return;
        }

        const output = message || this.formatMessage('loadError');
        panelInner.innerHTML = '<div class="notice notice-error"><p>' + JobSetupController.escapeHtml(output) + '</p></div>';
    }
}

class ServerTimeTicker {
    constructor(element) {
        this.element = element;
        this.clockElement = element ? element.querySelector('.exmoau-job-time__clock') : null;
        this.baseTimestamp = 0;
        this.offsetSeconds = 0;
        this.startTimeMs = 0;
    }

    init() {
        if (!this.element || !this.clockElement) {
            return;
        }

        const timestampAttr = this.element.getAttribute('data-exmoau-server-timestamp');
        const offsetAttr = this.element.getAttribute('data-exmoau-server-offset');

        const baseTimestamp = parseInt(timestampAttr, 10);
        const offsetSeconds = parseInt(offsetAttr, 10);

        if (isNaN(baseTimestamp) || isNaN(offsetSeconds)) {
            return;
        }

        this.baseTimestamp = baseTimestamp;
        this.offsetSeconds = offsetSeconds;
        this.startTimeMs = Date.now();

        this.update();
        window.setInterval(() => {
            this.update();
        }, 1000);
    }

    update() {
        const elapsedSeconds = Math.floor((Date.now() - this.startTimeMs) / 1000);
        const currentUtc = this.baseTimestamp + elapsedSeconds;
        const adjustedTimestamp = currentUtc + this.offsetSeconds;
        const date = new Date(adjustedTimestamp * 1000);

        const formatted = this.formatDate(date);
        const isoString = this.formatIso(date);

        this.clockElement.textContent = formatted;
        this.clockElement.setAttribute('datetime', isoString);
    }

    formatDate(date) {
        const year = date.getUTCFullYear();
        const month = this.pad(date.getUTCMonth() + 1);
        const day = this.pad(date.getUTCDate());
        const hours = this.pad(date.getUTCHours());
        const minutes = this.pad(date.getUTCMinutes());
        const seconds = this.pad(date.getUTCSeconds());

        return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
    }

    formatIso(date) {
        const year = date.getUTCFullYear();
        const month = this.pad(date.getUTCMonth() + 1);
        const day = this.pad(date.getUTCDate());
        const hours = this.pad(date.getUTCHours());
        const minutes = this.pad(date.getUTCMinutes());
        const seconds = this.pad(date.getUTCSeconds());

        const sign = this.offsetSeconds >= 0 ? '+' : '-';
        const absolute = Math.abs(this.offsetSeconds);
        const offsetHours = this.pad(Math.floor(absolute / 3600));
        const offsetMinutes = this.pad(Math.floor((absolute % 3600) / 60));

        return `${year}-${month}-${day}T${hours}:${minutes}:${seconds}${sign}${offsetHours}:${offsetMinutes}`;
    }

    pad(value) {
        return String(value).padStart(2, '0');
    }
}

class JobsMeta {
    constructor(doc = document) {
        this.document = doc;
        this.isInitialized = false;
        this.typeControllers = [];
        this.setupControllers = [];
        this.serverTimeTickers = [];
    }

    init() {
        if (this.isInitialized) {
            return;
        }

        const doc = this.document;

        const typeContainers = doc.querySelectorAll('[data-exmoau-job-meta="type"]');
        this.typeControllers = Array.from(typeContainers).map((container) => {
            const controller = new JobTypeController(container);
            controller.init();
            return controller;
        });

        const setupContainers = doc.querySelectorAll('[data-exmoau-job-meta="setup"]');
        this.setupControllers = Array.from(setupContainers).map((container) => {
            const controller = new JobSetupController(container);
            controller.init();
            return controller;
        });

        const serverTimeElements = doc.querySelectorAll('[data-exmoau-job-server-time]');
        this.serverTimeTickers = Array.from(serverTimeElements).map((element) => {
            const ticker = new ServerTimeTicker(element);
            ticker.init();
            return ticker;
        });

        this.isInitialized = true;
    }

}

window.ExoJobsMeta = new JobsMeta();
