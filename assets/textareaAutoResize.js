const textareaTypes = new Set([
    'APL', 'CERT', 'CDNSKEY', 'CDS', 'CSYNC', 'DHCID', 'DLV', 'DNSKEY', 'DS',
    'HTTPS', 'IPSECKEY', 'KEY', 'LUA', 'NAPTR', 'NSEC', 'NSEC3', 'NSEC3PARAM',
    'OPENPGPKEY', 'REGEX', 'RKEY', 'RRSIG', 'SIG', 'SMIMEA', 'SPF', 'SSHFP', 'SVCB',
    'TLSA', 'TKEY', 'TSIG', 'TXT', 'URI', 'ZONEMD'
]);

function escapeHtml(unsafe) {
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function resolveElement(elementOrId) {
    return typeof elementOrId === 'string' ? document.getElementById(elementOrId) : elementOrId;
}

function updateContentInput(selectId, containerId, contentId) {
    const elements = {
        select: resolveElement(selectId),
        container: resolveElement(containerId)
    };

    if (!elements.select || !elements.container) return;

    const currentInput = resolveElement(contentId);
    const currentClasses = currentInput ? currentInput.classList.toString() : '';
    const currentValue = currentInput ? currentInput.value : '';
    const currentName = currentInput ? currentInput.name : 'content';
    const currentId = currentInput && currentInput.id ? currentInput.id : '';
    const idAttribute = currentId ? ` id="${escapeHtml(currentId)}"` : '';
    const isTextarea = textareaTypes.has((elements.select.value || '').toUpperCase());

    const escapedValue = escapeHtml(currentValue);

    elements.container.innerHTML = isTextarea
        ? `<textarea${idAttribute} class="${currentClasses}" name="${currentName}" rows="1" required>${escapedValue}</textarea>`
        : `<input${idAttribute} class="${currentClasses}" type="text" name="${currentName}" value="${escapedValue}" data-testid="record-content-input" required>`;

    if (isTextarea) {
        const textarea = currentId ? document.getElementById(currentId) : elements.container.querySelector('textarea');
        const adjustHeight = () => {
            textarea.style.height = 'auto';
            textarea.style.height = `${textarea.scrollHeight}px`;
        };

        textarea.removeEventListener('input', adjustHeight);
        textarea.addEventListener('input', adjustHeight);
        if (textarea.value) adjustHeight();
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const containers = document.querySelectorAll('[id^="contentInputContainer"]');
    containers.forEach(container => {
        const baseString = "contentInputContainer";
        const suffix = container.id.slice(baseString.length);

        const selectId = `recordTypeSelect${suffix}`;
        const contentId = `recordContent${suffix}`;
        const select = document.getElementById(selectId);

        if (container && select) {
            container.dataset.initialValue = document.getElementById(contentId).value;
            updateContentInput(selectId, container.id, contentId);
            select.addEventListener('change', () => updateContentInput(selectId, container.id, contentId));
            select.addEventListener('input', () => updateContentInput(selectId, container.id, contentId));
        }
    });
});
