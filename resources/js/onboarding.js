(() => {
    const state = { config: null, tourName: null, stepIndex: 0, activeTarget: null };

    const storageKey = (suffix) => `hardex_onboarding_${state.config?.context}_${state.config?.userKey}_${suffix}`;
    const postProgress = (payload) => {
        if (!state.config?.progressUrl) return;

        fetch(state.config.progressUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': state.config.csrf,
                Accept: 'application/json',
            },
            body: JSON.stringify(payload),
        }).catch(() => {});
    };

    const removeTourUi = () => {
        document.querySelectorAll('[data-hardex-tour-ui]').forEach((node) => node.remove());

        if (state.activeTarget) {
            state.activeTarget.classList.remove('hardex-tour-highlight');
            state.activeTarget = null;
        }
    };

    const currentTour = () => state.config?.tours?.[state.tourName] || [];

    const finishTour = (skipped = false) => {
        const tour = currentTour();
        localStorage.setItem(storageKey(`tour_${state.tourName}`), skipped ? 'skipped' : 'completed');
        postProgress({
            tour_name: state.tourName,
            completed: !skipped,
            skipped,
            last_step: state.stepIndex,
        });
        removeTourUi();
    };

    const placePopover = (popover, target) => {
        if (!target) {
            popover.style.left = '50%';
            popover.style.top = '50%';
            popover.style.transform = 'translate(-50%, -50%)';
            return;
        }

        const rect = target.getBoundingClientRect();
        const top = Math.min(window.innerHeight - 220, Math.max(16, rect.bottom + 14));
        const left = Math.min(window.innerWidth - 360, Math.max(16, rect.left));
        popover.style.left = `${left}px`;
        popover.style.top = `${top}px`;
        popover.style.transform = 'none';
    };

    const renderStep = () => {
        removeTourUi();

        const tour = currentTour();
        const step = tour[state.stepIndex];

        if (!step) {
            finishTour(false);
            return;
        }

        const target = document.querySelector(step.target);
        const overlay = document.createElement('div');
        overlay.dataset.hardexTourUi = 'overlay';
        overlay.className = 'fixed inset-0 z-[9990] bg-slate-950/70 backdrop-blur-sm';
        document.body.appendChild(overlay);

        if (target) {
            target.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'smooth' });
            target.classList.add('hardex-tour-highlight');
            state.activeTarget = target;
        }

        const popover = document.createElement('div');
        popover.dataset.hardexTourUi = 'popover';
        popover.className = 'fixed z-[9992] w-[calc(100vw-2rem)] max-w-sm rounded-xl border border-slate-200 bg-white p-4 text-slate-900 shadow-2xl dark:border-slate-700 dark:bg-slate-900 dark:text-white';
        popover.innerHTML = `
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wide text-cyan-600">Hatua ${state.stepIndex + 1} kati ya ${tour.length}</p>
                    <h3 class="mt-1 text-lg font-bold">${step.title}</h3>
                </div>
                <button type="button" class="rounded-lg px-2 py-1 text-sm font-bold text-slate-500 hover:bg-slate-100 dark:hover:bg-white/10" data-tour-skip>Ruka</button>
            </div>
            <p class="mt-3 text-sm leading-6 text-slate-600 dark:text-slate-300">${step.body}</p>
            <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
                <div class="h-full rounded-full bg-cyan-500" style="width: ${((state.stepIndex + 1) / tour.length) * 100}%"></div>
            </div>
            <div class="mt-4 flex items-center justify-between gap-2">
                <button type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold disabled:opacity-40 dark:border-slate-700" data-tour-prev ${state.stepIndex === 0 ? 'disabled' : ''}>Hatua Iliyopita</button>
                <div class="flex gap-2">
                    <button type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold dark:border-slate-700" data-tour-never>Usinionyeshe Tena</button>
                    <button type="button" class="rounded-lg bg-cyan-600 px-3 py-2 text-sm font-semibold text-white" data-tour-next>${state.stepIndex === tour.length - 1 ? 'Maliza Mwongozo' : 'Hatua Inayofuata'}</button>
                </div>
            </div>
        `;
        document.body.appendChild(popover);

        window.setTimeout(() => placePopover(popover, target), target ? 260 : 0);

        popover.querySelector('[data-tour-prev]')?.addEventListener('click', () => {
            state.stepIndex = Math.max(0, state.stepIndex - 1);
            postProgress({ tour_name: state.tourName, last_step: state.stepIndex });
            renderStep();
        });
        popover.querySelector('[data-tour-next]')?.addEventListener('click', () => {
            state.stepIndex += 1;
            postProgress({ tour_name: state.tourName, last_step: state.stepIndex });
            renderStep();
        });
        popover.querySelector('[data-tour-skip]')?.addEventListener('click', () => finishTour(true));
        popover.querySelector('[data-tour-never]')?.addEventListener('click', () => {
            localStorage.setItem(storageKey('never'), '1');
            finishTour(true);
        });
    };

    const startTour = (tourName) => {
        if (!state.config?.tours?.[tourName]) return;

        state.tourName = tourName;
        state.stepIndex = 0;
        renderStep();
    };

    const showWelcome = () => {
        if (!state.config || localStorage.getItem(storageKey('never')) === '1') return;
        if (localStorage.getItem(storageKey('welcome_seen')) === '1') return;

        const modal = document.createElement('div');
        modal.dataset.hardexTourUi = 'welcome';
        modal.className = 'fixed inset-0 z-[9995] grid place-items-center bg-slate-950/75 p-4 backdrop-blur-md';
        modal.innerHTML = `
            <div class="w-full max-w-md rounded-xl border border-slate-200 bg-white p-5 text-slate-900 shadow-2xl dark:border-slate-700 dark:bg-slate-900 dark:text-white">
                <p class="text-xs font-bold uppercase tracking-wide text-cyan-600">${state.config.role}</p>
                <h2 class="mt-2 text-2xl font-bold">${state.config.welcome.title}</h2>
                <p class="mt-3 whitespace-pre-line text-sm leading-6 text-slate-600 dark:text-slate-300">${state.config.welcome.message}</p>
                <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold dark:border-slate-700" data-welcome-never>Usinionyeshe Tena</button>
                    <button type="button" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold dark:border-slate-700" data-welcome-skip>Ruka Mwongozo</button>
                    <button type="button" class="rounded-lg bg-cyan-600 px-3 py-2 text-sm font-semibold text-white" data-welcome-start>Anza Mwongozo</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        modal.querySelector('[data-welcome-start]')?.addEventListener('click', () => {
            localStorage.setItem(storageKey('welcome_seen'), '1');
            modal.remove();
            startTour(state.config.defaultTour);
        });
        modal.querySelector('[data-welcome-skip]')?.addEventListener('click', () => {
            localStorage.setItem(storageKey('welcome_seen'), '1');
            postProgress({ tour_name: state.config.defaultTour, skipped: true });
            modal.remove();
        });
        modal.querySelector('[data-welcome-never]')?.addEventListener('click', () => {
            localStorage.setItem(storageKey('welcome_seen'), '1');
            localStorage.setItem(storageKey('never'), '1');
            postProgress({ tour_name: state.config.defaultTour, skipped: true });
            modal.remove();
        });
    };

    const renderChecklist = () => {
        const root = document.querySelector('[data-hardex-checklist]');
        if (!root || !state.config?.checklist) return;

        const itemsRoot = root.querySelector('[data-hardex-checklist-items]');
        const progressText = root.querySelector('[data-hardex-checklist-progress]');
        const bar = root.querySelector('[data-hardex-checklist-bar]');
        const checked = JSON.parse(localStorage.getItem(storageKey('checklist')) || '{}');

        const update = () => {
            const done = state.config.checklist.filter((item) => checked[item.key]).length;
            const total = state.config.checklist.length;
            progressText.textContent = `${done}/${total} Imekamilika`;
            bar.style.width = `${total ? (done / total) * 100 : 0}%`;
            localStorage.setItem(storageKey('checklist'), JSON.stringify(checked));
            postProgress({ tour_name: 'checklist', checklist: checked, last_step: done, completed: done === total });
        };

        itemsRoot.innerHTML = '';
        state.config.checklist.forEach((item) => {
            const label = document.createElement('label');
            label.className = 'flex items-center gap-3 rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold dark:border-slate-700';
            label.innerHTML = `<input type="checkbox" class="rounded border-slate-300 text-cyan-600 focus:ring-cyan-600" ${checked[item.key] ? 'checked' : ''}> <span>${item.label}</span>`;
            label.querySelector('input').addEventListener('change', (event) => {
                checked[item.key] = event.target.checked;
                update();
            });
            itemsRoot.appendChild(label);
        });
        update();
    };

    const init = () => {
        const root = document.querySelector('[data-hardex-onboarding]');
        if (!root) return;

        try {
            state.config = JSON.parse(root.dataset.hardexOnboarding);
        } catch (error) {
            return;
        }

        window.HardexOnboarding = { startTour };
        renderChecklist();
        window.setTimeout(showWelcome, 800);
    };

    document.addEventListener('DOMContentLoaded', init);
    document.addEventListener('livewire:navigated', init);
})();
