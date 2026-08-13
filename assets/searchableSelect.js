(function () {
    const SELECTOR = '.record-type-input, .record-type-select';
    const MENU_CLASS = 'searchable-select-menu';
    const ACTIVE_CLASS = 'is-active';

    let openMenu = null;

    function getOptions(input) {
        const listId = input.dataset.optionsSource || input.getAttribute('list');
        const list = listId ? document.getElementById(listId) : null;

        if (!list) {
            return [];
        }

        return Array.from(list.querySelectorAll('option')).map(option => ({
            value: option.value,
            label: option.getAttribute('label') || option.value,
        })).filter(option => option.value !== '');
    }

    function matchesOption(option, query) {
        if (!query) {
            return true;
        }

        const value = option.value.toUpperCase();
        const label = option.label.toUpperCase();

        return value.startsWith(query) || label.startsWith(query) || value.includes(query) || label.includes(query);
    }

    function sortOptions(options, query) {
        if (!query) {
            return options;
        }

        return options.slice().sort((a, b) => {
            const aValue = a.value.toUpperCase();
            const bValue = b.value.toUpperCase();
            const aStarts = aValue.startsWith(query) ? 0 : 1;
            const bStarts = bValue.startsWith(query) ? 0 : 1;

            if (aStarts !== bStarts) {
                return aStarts - bStarts;
            }

            return aValue.localeCompare(bValue);
        });
    }

    function positionMenu(input, menu) {
        const rect = input.getBoundingClientRect();
        menu.style.left = `${rect.left}px`;
        menu.style.top = `${rect.bottom + 4}px`;
        menu.style.width = `${rect.width}px`;
    }

    function closeMenu() {
        if (openMenu) {
            openMenu.remove();
            openMenu = null;
        }
    }

    function commitValue(input, value) {
        input.value = value.toUpperCase();
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        closeMenu();
    }

    function renderMenu(input) {
        closeMenu();

        const options = getOptions(input);
        const query = input.value.trim().toUpperCase();
        const filtered = sortOptions(options.filter(option => matchesOption(option, query)), query).slice(0, 12);

        const menu = document.createElement('div');
        menu.className = MENU_CLASS;
        menu.setAttribute('role', 'listbox');
        menu.id = `${input.id || input.name.replace(/[^a-z0-9_-]/gi, '-')}-searchable-menu`;

        if (filtered.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'searchable-select-empty';
            empty.textContent = input.dataset.emptyText || 'No matching types';
            menu.appendChild(empty);
        } else {
            filtered.forEach((option, index) => {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'searchable-select-option';
                item.setAttribute('role', 'option');
                item.dataset.value = option.value;
                item.tabIndex = -1;

                const value = document.createElement('span');
                value.textContent = option.value;
                item.appendChild(value);

                if (option.label !== option.value) {
                    const label = document.createElement('small');
                    label.textContent = option.label;
                    item.appendChild(label);
                }

                if (index === 0) {
                    item.classList.add(ACTIVE_CLASS);
                }
                item.addEventListener('mousedown', event => {
                    event.preventDefault();
                    commitValue(input, option.value);
                });
                menu.appendChild(item);
            });
        }

        document.body.appendChild(menu);
        positionMenu(input, menu);
        input.setAttribute('aria-controls', menu.id);
        input.setAttribute('aria-expanded', 'true');
        openMenu = menu;
    }

    function moveActive(menu, direction) {
        const items = Array.from(menu.querySelectorAll('.searchable-select-option'));
        if (items.length === 0) {
            return;
        }

        const currentIndex = Math.max(0, items.findIndex(item => item.classList.contains(ACTIVE_CLASS)));
        const nextIndex = (currentIndex + direction + items.length) % items.length;

        items[currentIndex].classList.remove(ACTIVE_CLASS);
        items[nextIndex].classList.add(ACTIVE_CLASS);
        items[nextIndex].scrollIntoView({ block: 'nearest' });
    }

    function initInput(input, force) {
        if (!force && input.dataset.searchableSelectReady === 'true') {
            return;
        }

        input.dataset.searchableSelectReady = 'true';
        input.dataset.optionsSource = input.dataset.optionsSource || input.getAttribute('list') || '';
        input.removeAttribute('list');
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');

        input.addEventListener('focus', () => renderMenu(input));
        input.addEventListener('input', () => renderMenu(input));
        input.addEventListener('keydown', event => {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                if (!openMenu) {
                    renderMenu(input);
                } else {
                    moveActive(openMenu, 1);
                }
                return;
            }

            if (event.key === 'ArrowUp') {
                event.preventDefault();
                if (openMenu) {
                    moveActive(openMenu, -1);
                }
                return;
            }

            if (event.key === 'Enter' && openMenu) {
                const active = openMenu.querySelector(`.${ACTIVE_CLASS}`);
                if (active) {
                    event.preventDefault();
                    commitValue(input, active.dataset.value);
                }
                return;
            }

            if (event.key === 'Escape') {
                input.setAttribute('aria-expanded', 'false');
                closeMenu();
            }
        });
        input.addEventListener('blur', () => {
            input.value = input.value.toUpperCase();
            input.setAttribute('aria-expanded', 'false');
            window.setTimeout(closeMenu, 120);
        });
    }

    window.initSearchableSelects = function (root, force) {
        (root || document).querySelectorAll(SELECTOR).forEach(input => initInput(input, Boolean(force)));
    };

    document.addEventListener('mousedown', event => {
        if (!event.target.closest(`.${MENU_CLASS}`) && !event.target.matches(SELECTOR)) {
            closeMenu();
        }
    });

    window.addEventListener('resize', closeMenu);
    window.addEventListener('scroll', closeMenu, true);
    document.addEventListener('DOMContentLoaded', () => window.initSearchableSelects(document, false));
})();
