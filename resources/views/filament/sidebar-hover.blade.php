<style>
    .fi-sidebar {
        width: 17rem !important;
        min-width: 17rem !important;
        transition: width 0.25s ease !important;
        position: relative !important;
    }

    .fi-sidebar:not(.sidebar-pinned) {
        width: 5rem !important;
        min-width: 5rem !important;
    }

    .fi-sidebar.sidebar-pinned {
        width: 17rem !important;
        min-width: 17rem !important;
    }

    .fi-sidebar:not(.sidebar-pinned):hover {
        width: 17rem !important;
        min-width: 17rem !important;
    }

    /* Unpinned: icons only — hide all labels/badges (Filament v4 class names) */
    .fi-sidebar:not(.sidebar-pinned):not(:hover) .fi-sidebar-item-label,
    .fi-sidebar:not(.sidebar-pinned):not(:hover) .fi-sidebar-group-label,
    .fi-sidebar:not(.sidebar-pinned):not(:hover) .fi-sidebar-item-badge-ctn,
    .fi-sidebar:not(.sidebar-pinned):not(:hover) .fi-brand-name,
    .fi-sidebar:not(.sidebar-pinned):not(:hover) .fi-sidebar-header .truncate {
        display: none !important;
        visibility: hidden !important;
        width: 0 !important;
        max-width: 0 !important;
        padding: 0 !important;
        margin: 0 !important;
        overflow: hidden !important;
        opacity: 0 !important;
        pointer-events: none !important;
    }

    .fi-sidebar:not(.sidebar-pinned):not(:hover) .fi-sidebar-group-collapse-btn {
        display: none !important;
    }

    .fi-sidebar:not(.sidebar-pinned):not(:hover) .fi-sidebar-item-grouped-border {
        display: none !important;
    }

    .fi-sidebar:not(.sidebar-pinned):not(:hover) .fi-sidebar-item-btn,
    .fi-sidebar:not(.sidebar-pinned):not(:hover) .fi-sidebar-group-btn {
        justify-content: center !important;
        padding-left: 0.75rem !important;
        padding-right: 0.75rem !important;
    }

    .fi-sidebar:hover .fi-sidebar-item-label,
    .fi-sidebar:hover .fi-sidebar-group-label,
    .fi-sidebar:hover .fi-sidebar-item-badge-ctn,
    .fi-sidebar:hover .fi-brand-name,
    .fi-sidebar:hover .fi-sidebar-header .truncate,
    .fi-sidebar.sidebar-pinned .fi-sidebar-item-label,
    .fi-sidebar.sidebar-pinned .fi-sidebar-group-label,
    .fi-sidebar.sidebar-pinned .fi-sidebar-item-badge-ctn,
    .fi-sidebar.sidebar-pinned .fi-brand-name,
    .fi-sidebar.sidebar-pinned .fi-sidebar-header .truncate {
        display: revert !important;
        visibility: visible !important;
        width: auto !important;
        max-width: none !important;
        opacity: 1 !important;
        pointer-events: auto !important;
    }

    .fi-sidebar:hover .fi-sidebar-group-collapse-btn,
    .fi-sidebar.sidebar-pinned .fi-sidebar-group-collapse-btn {
        display: revert !important;
    }

    .fi-sidebar:hover .fi-sidebar-item-grouped-border,
    .fi-sidebar.sidebar-pinned .fi-sidebar-item-grouped-border {
        display: revert !important;
    }

    .fi-sidebar .fi-sidebar-item-btn,
    .fi-sidebar .fi-sidebar-group-btn {
        display: flex !important;
        align-items: center !important;
        justify-content: flex-start !important;
    }

    .fi-sidebar:hover .fi-sidebar-item-btn,
    .fi-sidebar:hover .fi-sidebar-group-btn,
    .fi-sidebar.sidebar-pinned .fi-sidebar-item-btn,
    .fi-sidebar.sidebar-pinned .fi-sidebar-group-btn {
        justify-content: flex-start !important;
        padding-left: 1rem !important;
        padding-right: 0.75rem !important;
    }

    .fi-sidebar .fi-sidebar-item-icon,
    .fi-sidebar svg:not(.sidebar-pin-btn svg) {
        flex-shrink: 0 !important;
        min-width: 1.25rem !important;
    }

    /* Remove top gap so Dashboard sits flush against the pin bar */
    .fi-sidebar-nav {
        padding-top: 0 !important;
    }
    .fi-sidebar-nav > nav,
    .fi-sidebar-nav > div:first-child {
        padding-top: 0 !important;
        margin-top: 0 !important;
    }

    /* ── Pin bar (light default, dark override) ── */
    .sidebar-pin-bar {
        display: flex;
        align-items: center;
        justify-content: center;
        height: 36px;
        padding: 0 10px;
        background: #f9fafb;
        border-bottom: 1px solid #eaecf0;
        flex-shrink: 0;
        overflow: hidden;
        transition: justify-content 0.2s, background 0.2s, border-color 0.2s;
    }

    html.dark .sidebar-pin-bar {
        background: #111827;
        border-bottom: 1px solid #1f2937;
    }

    .fi-sidebar:hover .sidebar-pin-bar,
    .fi-sidebar.sidebar-pinned .sidebar-pin-bar {
        justify-content: space-between;
    }

    /* Label – visible only when expanded */
    .sidebar-pin-label {
        font-size: 10px;
        font-weight: 600;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: #9ca3af;
        white-space: nowrap;
        overflow: hidden;
        opacity: 0;
        max-width: 0;
        transition: opacity 0.2s ease, max-width 0.25s ease, color 0.2s;
        user-select: none;
    }

    html.dark .sidebar-pin-label {
        color: #64748b;
    }

    .fi-sidebar:hover .sidebar-pin-label,
    .fi-sidebar.sidebar-pinned .sidebar-pin-label {
        opacity: 1;
        max-width: 140px;
    }

    /* ── Pin button ── */
    .sidebar-pin-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 26px;
        height: 26px;
        border-radius: 6px;
        background: transparent;
        border: 1.5px solid #e5e7eb;
        cursor: pointer;
        transition: background 0.15s, border-color 0.15s, color 0.15s, box-shadow 0.15s;
        color: #9ca3af;
        flex-shrink: 0;
        outline: none;
    }

    .sidebar-pin-btn:hover {
        background: #f3f4f6;
        border-color: #d1d5db;
        color: #374151;
        box-shadow: none;
    }

    html.dark .sidebar-pin-btn {
        border-color: #475569;
        color: #94a3b8;
    }

    html.dark .sidebar-pin-btn:hover {
        background: #1f2937;
        border-color: #64748b;
        color: #e2e8f0;
    }

    .sidebar-pin-btn:focus-visible {
        box-shadow: 0 0 0 2px #1B4F7230;
        border-color: #1B4F72;
    }

    .sidebar-pin-btn.pinned {
        background: rgba(27, 79, 114, 0.08);
        border-color: rgba(27, 79, 114, 0.45);
        color: #1B4F72;
    }

    .sidebar-pin-btn.pinned:hover {
        background: rgba(27, 79, 114, 0.14);
        border-color: #1B4F72;
        box-shadow: none;
    }

    html.dark .sidebar-pin-btn.pinned {
        background: rgba(27, 79, 114, 0.15);
        border-color: rgba(46, 106, 150, 0.45);
        color: #C4A35A;
    }

    html.dark .sidebar-pin-btn.pinned:hover {
        background: rgba(27, 79, 114, 0.22);
        border-color: #2E6A96;
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const sidebar = document.querySelector('.fi-sidebar');
        if (!sidebar) return;

        // ── Build the pin bar ──
        const pinBar = document.createElement('div');
        pinBar.className = 'sidebar-pin-bar';

        const label = document.createElement('span');
        label.className = 'sidebar-pin-label';

        const pinBtn = document.createElement('button');
        pinBtn.type = 'button';
        pinBtn.className = 'sidebar-pin-btn';

        pinBar.appendChild(label);
        pinBar.appendChild(pinBtn);

        // Insert at the top of the nav so it sits directly above Dashboard
        const navEl = sidebar.querySelector('.fi-sidebar-nav') || sidebar;
        navEl.insertBefore(pinBar, navEl.firstChild);

        // ── SVG icons ──
        const iconUnpinned = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg">
            <path d="M9 3h6"/>
            <path d="M12 3v5"/>
            <path d="M8 8h8a1 1 0 0 1 1 1v1a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/>
            <line x1="12" y1="11" x2="12" y2="21"/>
        </svg>`;

        const iconPinned = `<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M9 3h6" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/>
            <path d="M12 3v5" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/>
            <path d="M8 8h8a1 1 0 0 1 1 1v1a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/>
            <line x1="12" y1="11" x2="12" y2="21" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/>
        </svg>`;

        function updateIcon(pinned) {
            if (pinned) {
                pinBtn.classList.add('pinned');
                pinBtn.title = 'Unpin sidebar';
                pinBtn.innerHTML = iconPinned;
                label.textContent = 'Sidebar pinned';
            } else {
                pinBtn.classList.remove('pinned');
                pinBtn.title = 'Pin sidebar open';
                pinBtn.innerHTML = iconUnpinned;
                label.textContent = 'Keep open';
            }
        }

        // Restore persisted state — default to pinned open on first visit
        const saved = localStorage.getItem('sidebar-pinned');
        const isPinned = saved === null ? true : saved === 'true';
        if (isPinned) {
            sidebar.classList.add('sidebar-pinned');
            localStorage.setItem('sidebar-pinned', 'true');
        }
        updateIcon(isPinned);

        // Toggle pin on click
        pinBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            const pinned = sidebar.classList.toggle('sidebar-pinned');
            localStorage.setItem('sidebar-pinned', pinned);
            updateIcon(pinned);
        });

        // Hover expand / collapse (only when not pinned) — CSS handles labels; width only here
        sidebar.addEventListener('mouseenter', function () {
            if (sidebar.classList.contains('sidebar-pinned')) return;
            sidebar.style.width = '17rem';
            sidebar.style.minWidth = '17rem';
        });

        sidebar.addEventListener('mouseleave', function () {
            if (sidebar.classList.contains('sidebar-pinned')) return;
            sidebar.style.width = '5rem';
            sidebar.style.minWidth = '5rem';
        });
    });
</script>
