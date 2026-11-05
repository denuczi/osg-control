import 'bootstrap';
import Chart from 'chart.js/auto';

// Older server-rendered screens use the same installation zone as Inertia.
const legacyDisplayZone = document.querySelector('meta[name="display-timezone"]')?.getAttribute('content') || 'UTC';
function parseServerUtc(value) {
    const text = String(value ?? '').trim().replace(' ', 'T');
    const complete = /(?:Z|[+-]\d{2}:\d{2})$/i.test(text) ? text : `${text}Z`;
    return new Date(complete);
}

const chartNode = document.getElementById('performanceChart');
if (chartNode) {
    const wrapper = chartNode.closest('[data-chart-url]');
    const empty = document.getElementById('chartEmpty');
    let chart = null;
    const buttons = document.querySelectorAll('[data-hours]');
    let selectedHours = '1';
    let chartTimer;
    let chartDelay = 60000;
    const loadChart = async (hours) => {
        try {
            const url = new URL(wrapper.dataset.chartUrl, window.location.href);
            url.searchParams.set('hours', hours);
            const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error('No fue posible consultar métricas.');
            const { samples, next_refresh_seconds } = await response.json();
            chartDelay = Math.min(300000, Math.max(5000, Number(next_refresh_seconds || 60) * 1000));
            if (chart) { chart.destroy(); chart = null; }
            empty.textContent = samples.length ? '' : 'No hay muestras disponibles para este período.';
            empty.classList.toggle('d-none', samples.length > 0);
            if (!samples.length) return;
            chart = new Chart(chartNode, {
                type: 'line',
                data: {
                    labels: samples.map(s => parseServerUtc(s.time).toLocaleTimeString('es', { hour: '2-digit', minute: '2-digit', timeZone: legacyDisplayZone })),
                    datasets: [
                        { label: 'CPU', data: samples.map(s => s.cpu), borderColor: '#d34615', backgroundColor: 'rgba(211,70,21,.07)', tension: .32, borderWidth: 2, pointRadius: 0, fill: true },
                        { label: 'Memoria', data: samples.map(s => s.memory), borderColor: '#737373', backgroundColor: 'transparent', tension: .32, borderWidth: 2, pointRadius: 0 },
                    ],
                },
                options: {
                    responsive: true, maintainAspectRatio: false, animation: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 20, color: '#666666', font: { size: 11 } } } },
                    scales: {
                        y: { min: 0, max: 100, ticks: { stepSize: 25, callback: v => `${v}%`, color: '#777777' }, grid: { color: '#ececec' }, border: { display: false } },
                        x: { ticks: { maxTicksLimit: 8, color: '#777777', maxRotation: 0 }, grid: { display: false }, border: { display: false } },
                    },
                },
            });
        } catch {
            if (chart) { chart.destroy(); chart = null; }
            empty.textContent = 'No fue posible obtener el historial. Reintentá más tarde.';
            empty.classList.remove('d-none');
        }
    };
    const scheduleChart = async () => {
        clearTimeout(chartTimer);
        if (!document.hidden) await loadChart(selectedHours);
        chartTimer = setTimeout(scheduleChart, chartDelay);
    };
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) scheduleChart();
    });
    buttons.forEach(button => button.addEventListener('click', () => {
        selectedHours = button.dataset.hours;
        buttons.forEach(b => b.classList.remove('active'));
        button.classList.add('active');
        scheduleChart();
    }));
    scheduleChart();
}

const actionModal = document.getElementById('actionModal');
if (actionModal) {
    actionModal.addEventListener('show.bs.modal', event => {
        const button = event.relatedTarget;
        const action = button?.dataset.action;
        const pid = button?.dataset.pid || '';
        const processName = button?.dataset.processName || '';
        const startedAt = button?.dataset.startedAt || '';
        const descriptions = {
            terminate_process: `Se solicitará finalizar el proceso «${processName}» (PID ${pid}).`,
            restart: 'Se solicitará reiniciar esta computadora. Se pueden cerrar sesiones y aplicaciones.',
            shutdown: 'Se solicitará apagar esta computadora. Podrías perder el acceso remoto hasta encenderla nuevamente.',
        };
        document.getElementById('commandAction').value = action || '';
        document.getElementById('commandPid').value = pid;
        document.getElementById('commandProcessName').value = processName;
        document.getElementById('commandProcessStartedAt').value = startedAt;
        document.getElementById('actionDescription').textContent = descriptions[action] || '';
        actionModal.querySelector('[name="confirm"]').checked = false;
    });
}

// Consulta incremental: los valores reflejan datos del agente, nunca datos simulados.
const statusNode = document.querySelector('[data-device-status-url]');
if (statusNode) {
    const fmt = new Intl.NumberFormat('es-AR', { maximumFractionDigits: 1, minimumFractionDigits: 1 });
    let deviceDelay = 60000;
    let deviceFailures = 0;
    const updateDevice = async () => {
        if (document.hidden) { setTimeout(updateDevice, deviceDelay); return; }
        try {
            const response = await fetch(statusNode.dataset.deviceStatusUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error('HTTP ' + response.status);
            const state = await response.json();
            deviceDelay = Math.min(300000, Math.max(5000, Number(state.next_refresh_seconds || 60) * 1000));
            deviceFailures = 0;
            document.querySelector('[data-device-sync-error]')?.classList.add('d-none');
            const status = document.querySelector('[data-live-status] .device-status');
            if (status) {
                status.className = 'device-status ' + (state.online ? 'online' : 'offline');
                status.lastChild.textContent = state.demo ? (state.online ? 'Demo · datos ficticios' : 'Demo · sin conexión') : (state.online ? 'En línea' : 'Sin conexión');
            }
            ['cpu', 'memory', 'disk'].forEach(metric => {
                const value = state[metric];
                const number = document.querySelector(`[data-metric="${metric}"]`);
                if (number) number.textContent = value == null ? '—' : fmt.format(value) + '%';
                const bar = document.querySelector(`[data-metric-progress="${metric}"]`);
                if (bar) {
                    bar.style.width = (value ?? 0) + '%';
                    bar.classList.toggle('bg-danger', value != null && value >= 85);
                    bar.parentElement.setAttribute('aria-valuenow', value ?? 0);
                }
            });
            for (const control of document.querySelectorAll('[data-remote-action]')) control.disabled = !state.online || state.demo;
            const seen = document.querySelector('[data-last-seen]');
            if (seen) seen.textContent = state.last_seen ? parseServerUtc(state.last_seen).toLocaleString('es', {timeZone: legacyDisplayZone}) : 'Nunca';
            const tbody = document.querySelector('[data-process-rows]');
            if (!tbody) return;
            tbody.replaceChildren();
            if (!state.processes.length) {
                const row = document.createElement('tr');
                const cell = row.insertCell();
                cell.colSpan = 4;
                cell.className = 'text-center p-5 text-secondary';
                cell.textContent = state.online ? 'No hay procesos reportados.' : 'Equipo desconectado. No se muestran procesos antiguos como actuales.';
                tbody.append(row);
                return;
            }
            state.processes.forEach(process => {
                const row = document.createElement('tr');
                const label = row.insertCell();
                const name = document.createElement('span'); name.className = 'primary-cell'; name.textContent = process.name; label.append(name);
                const pid = row.insertCell(); pid.className = 'font-monospace text-secondary small'; pid.textContent = String(process.pid);
                const memory = row.insertCell(); memory.textContent = fmt.format(process.memory_mb) + ' MB';
                const actions = row.insertCell(); actions.className = 'text-end';
                const button = document.createElement('button');
                button.type = 'button'; button.className = 'btn btn-table btn-danger-soft';
                button.dataset.bsToggle = 'modal'; button.dataset.bsTarget = '#actionModal';
                button.dataset.action = 'terminate_process'; button.dataset.pid = process.pid;
                button.disabled = !process.can_terminate || state.demo;
                button.dataset.processName = process.name; button.dataset.startedAt = process.started_at;
                button.setAttribute('aria-label', 'Solicitar cierre de ' + process.name);
                const i = document.createElement('i'); i.className = 'bi bi-x-lg'; button.append(i);
                actions.append(button); tbody.append(row);
            });
            filterProcesses();
        } catch {
            deviceFailures++;
            deviceDelay = Math.min(120000, 5000 * 2 ** Math.min(deviceFailures, 5));
            // No presentar mediciones desactualizadas como estado en vivo.
            document.querySelector('[data-device-sync-error]')?.classList.remove('d-none');
        } finally { setTimeout(updateDevice, deviceDelay); }
    };
    setTimeout(updateDevice, deviceDelay);
}

const dashboardNode = document.querySelector('[data-dashboard-url]');
if (dashboardNode) {
    let dashboardDelay = 60000;
    let dashboardFailures = 0;
    const updateDashboard = async () => {
        if (document.hidden) { setTimeout(updateDashboard, dashboardDelay); return; }
        try {
            const response = await fetch(dashboardNode.dataset.dashboardUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error('HTTP ' + response.status);
            const data = await response.json();
            dashboardDelay = Math.min(300000, Math.max(5000, Number(data.next_refresh_seconds || 60) * 1000));
            dashboardFailures = 0;
            document.querySelector('[data-dashboard-sync-error]')?.classList.add('d-none');
            const totalNode = document.querySelector('[data-kpi="total"]');
            if (totalNode && Number(totalNode.textContent) !== data.total) { window.location.reload(); return; }
            const onlineNode = document.querySelector('[data-kpi="online"]');
            if (onlineNode) onlineNode.textContent = data.online;
            const avgNode = document.querySelector('[data-kpi="average_cpu"]');
            if (avgNode) avgNode.textContent = data.average_cpu == null ? '—' : Number(data.average_cpu).toLocaleString('es-AR', { maximumFractionDigits: 1 }) + '%';
            data.devices.forEach(device => {
                const row = [...document.querySelectorAll('[data-device-id]')].find(el => el.dataset.deviceId === device.uuid);
                if (!row) return;
                const status = row.querySelector('[data-live-status] .device-status');
                if (status) {
                    status.className = 'device-status ' + (device.online ? 'online' : 'offline');
                    status.lastChild.textContent = device.demo ? (device.online ? 'Demo · datos ficticios' : 'Demo · sin conexión') : (device.online ? 'En línea' : 'Sin conexión');
                }
                const cpu = row.querySelector('[data-live-cpu]');
                const memory = row.querySelector('[data-live-memory]');
                if (cpu) cpu.textContent = device.cpu == null ? '—' : Math.round(device.cpu) + '%';
                if (memory) memory.textContent = device.memory == null ? '—' : Math.round(device.memory) + '%';
            });
        } catch {
            dashboardFailures++;
            dashboardDelay = Math.min(120000, 5000 * 2 ** Math.min(dashboardFailures, 5));
            document.querySelector('[data-dashboard-sync-error]')?.classList.remove('d-none');
        } finally { setTimeout(updateDashboard, dashboardDelay); }
    };
    setTimeout(updateDashboard, dashboardDelay);
}

const processSearch = document.getElementById('processSearch');
function filterProcesses() {
    const term = (processSearch?.value || '').trim().toLocaleLowerCase();
    document.querySelectorAll('[data-process-rows] tr').forEach(row => {
        row.hidden = term !== '' && !row.textContent.toLocaleLowerCase().includes(term);
    });
}
if (processSearch) processSearch.addEventListener('input', filterProcesses);

// The refresh button performs an actual navigation and never simulates a sync state.
document.getElementById('refreshPage')?.addEventListener('click', () => window.location.reload());
