@extends('layouts.app')

@section('content')
@php
    $currency = static fn ($amount) => number_format((float) $amount, 2, '.', ' ') . ' ₽';
@endphp
<div class="py-10">
    <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        <div class="flex flex-col gap-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:flex-row sm:items-end sm:justify-between">
            <div>
                <div class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">Администрирование</div>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-slate-900">Статистика</h1>
                <p class="mt-2 max-w-2xl text-sm text-slate-600">
                    Дневная динамика по пользователям, подпискам и платежам. Поступления и выручка считаются по
                    платежам `type=topup`.
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                @foreach ($allowedDayRanges as $range)
                    @php
                        $active = $selectedRange === (string) $range;
                    @endphp
                    <a
                        href="{{ route('admin.statistics.index', ['days' => $range]) }}"
                        class="inline-flex items-center rounded-full px-4 py-2 text-sm font-semibold transition"
                        style="{{ $active
                            ? 'background:#0f172a;color:#fff;box-shadow:0 10px 25px rgba(15,23,42,.18);'
                            : 'background:#f8fafc;color:#334155;border:1px solid #cbd5e1;' }}"
                    >
                        {{ $range }} дней
                    </a>
                @endforeach
                @php
                    $allTimeActive = $selectedRange === $allTimeRangeValue;
                @endphp
                <a
                    href="{{ route('admin.statistics.index', ['days' => $allTimeRangeValue]) }}"
                    class="inline-flex items-center rounded-full px-4 py-2 text-sm font-semibold transition"
                    style="{{ $allTimeActive
                        ? 'background:#0f172a;color:#fff;box-shadow:0 10px 25px rgba(15,23,42,.18);'
                        : 'background:#f8fafc;color:#334155;border:1px solid #cbd5e1;' }}"
                >
                    За весь период
                </a>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-sm font-medium text-slate-500">Всего пользователей</div>
                <div class="mt-3 text-3xl font-semibold text-slate-900">{{ number_format((int) $summary['total_users'], 0, '.', ' ') }}</div>
                <div class="mt-2 text-xs text-slate-500">График ниже показывает общее число пользователей на конец каждого дня.</div>
            </div>
            <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-sm font-medium text-slate-500">Подключения за период</div>
                <div class="mt-3 text-3xl font-semibold text-slate-900">{{ number_format((int) $summary['period_subscriptions'], 0, '.', ' ') }}</div>
                <div class="mt-2 text-xs text-slate-500">Считаются записи `user_subscriptions` с действиями `create/activate`.</div>
            </div>
            <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-sm font-medium text-slate-500">Поступления за период</div>
                <div class="mt-3 text-3xl font-semibold text-slate-900">{{ number_format((int) $summary['period_payment_count'], 0, '.', ' ') }}</div>
                <div class="mt-2 text-xs text-slate-500">Количество пополнений баланса {{ $rangeDescription }}.</div>
            </div>
            <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-sm font-medium text-slate-500">Выручка за период</div>
                <div class="mt-3 text-3xl font-semibold text-slate-900">{{ $currency($summary['period_revenue_rub']) }}</div>
                <div class="mt-2 text-xs text-slate-500">
                    Среднее в день: {{ $currency($summary['avg_daily_revenue_rub']) }}.
                    Всего за всё время: {{ $currency($summary['total_revenue_rub']) }}.
                </div>
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">Подписки в день</h2>
                        <p class="mt-1 text-sm text-slate-500">Новые подключения и активации за выбранный период.</p>
                    </div>
                </div>
                <div class="mt-5 chart-shell">
                    <canvas id="subscriptionsChart" height="260"></canvas>
                </div>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">Пользователи и доходность в день</h2>
                        <p class="mt-1 text-sm text-slate-500">Линия показывает общее число пользователей на конец дня, столбики показывают оценку доходности: сумма абонплат активных обработанных подписок за вычетом доли партнёра, делённая на 30.</p>
                    </div>
                    <div class="flex flex-wrap justify-end gap-2 text-xs font-semibold">
                        <span class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-slate-600">
                            <span class="h-2.5 w-2.5 rounded-full bg-violet-600"></span>
                            Сейчас: {{ number_format((int) $summary['total_users'], 0, '.', ' ') }}
                        </span>
                        <span class="inline-flex items-center gap-2 rounded-full bg-rose-50 px-3 py-1 text-rose-700">
                            <span class="h-2.5 w-2.5 rounded-full bg-rose-500"></span>
                            Доходность: {{ $currency($summary['current_estimated_daily_income_rub']) }}/день
                        </span>
                    </div>
                </div>
                <div class="mt-5 chart-shell">
                    <canvas id="usersIncomeChart" height="260"></canvas>
                </div>
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm xl:col-span-2">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">Поступления и выручка</h2>
                        <p class="mt-1 text-sm text-slate-500">Столбики показывают количество `topup`-платежей, линия показывает выручку по дням.</p>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs font-semibold">
                        <span class="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 text-emerald-700">
                            <span class="h-2.5 w-2.5 rounded-full bg-teal-700"></span>
                            Поступления
                        </span>
                        <span class="inline-flex items-center gap-2 rounded-full bg-orange-50 px-3 py-1 text-orange-700">
                            <span class="h-2.5 w-2.5 rounded-full bg-orange-600"></span>
                            Выручка
                        </span>
                    </div>
                </div>
                <div class="mt-5 chart-shell">
                    <canvas id="paymentsRevenueChart" height="260"></canvas>
                </div>
            </section>
        </div>
    </div>
</div>

<style>
    .chart-shell {
        position: relative;
        width: 100%;
        min-height: 260px;
    }

    .chart-shell canvas {
        width: 100%;
        height: 260px;
        display: block;
    }

    .chart-tooltip {
        position: absolute;
        top: 0;
        left: 0;
        z-index: 10;
        min-width: 104px;
        max-width: 180px;
        padding: 10px 12px;
        border-radius: 14px;
        background: rgba(15, 23, 42, 0.94);
        color: #f8fafc;
        box-shadow: 0 18px 38px rgba(15, 23, 42, 0.22);
        pointer-events: none;
        opacity: 0;
        transform: translateY(6px);
        transition: opacity 120ms ease, transform 120ms ease;
    }

    .chart-tooltip.is-visible {
        opacity: 1;
        transform: translateY(0);
    }

    .chart-tooltip__label {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #cbd5e1;
    }

    .chart-tooltip__value {
        margin-top: 4px;
        font-size: 16px;
        font-weight: 700;
        color: #fff;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const chartData = @json($chartData);
    const moneyFormatter = new Intl.NumberFormat('ru-RU', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    });

    const charts = [];

    function formatDateLabel(value) {
        const parts = value.split('-');
        if (parts.length !== 3) {
            return value;
        }

        return `${parts[2]}.${parts[1]}.${parts[0]}`;
    }

    function getTooltip(canvas) {
        let tooltip = canvas.parentElement.querySelector('.chart-tooltip');
        if (!tooltip) {
            tooltip = document.createElement('div');
            tooltip.className = 'chart-tooltip';
            tooltip.innerHTML = '<div class="chart-tooltip__label"></div><div class="chart-tooltip__value"></div>';
            canvas.parentElement.appendChild(tooltip);
        }

        return tooltip;
    }

    function hideTooltip(canvas, shouldRerender = true) {
        const tooltip = getTooltip(canvas);
        tooltip.classList.remove('is-visible');

        if (canvas._activeIndex !== null && canvas._activeIndex !== undefined) {
            canvas._activeIndex = null;
            if (shouldRerender && typeof canvas._rerender === 'function') {
                canvas._rerender();
            }
        }
    }

    function showTooltip(canvas, entry, event, formatValue) {
        const tooltip = getTooltip(canvas);
        const labelNode = tooltip.querySelector('.chart-tooltip__label');
        const valueNode = tooltip.querySelector('.chart-tooltip__value');

        labelNode.textContent = formatDateLabel(entry.date);
        valueNode.textContent = formatValue(entry);

        tooltip.classList.add('is-visible');

        const rect = canvas.getBoundingClientRect();
        const shellRect = canvas.parentElement.getBoundingClientRect();
        const tooltipRect = tooltip.getBoundingClientRect();
        const pointerX = event.clientX - shellRect.left;
        const preferredY = entry.tooltipY ?? (event.clientY - rect.top);

        let left = pointerX - tooltipRect.width / 2;
        let top = preferredY - tooltipRect.height - 12;

        left = Math.max(8, Math.min(left, shellRect.width - tooltipRect.width - 8));
        if (top < 8) {
            top = Math.min(shellRect.height - tooltipRect.height - 8, preferredY + 14);
        }

        tooltip.style.left = `${left}px`;
        tooltip.style.top = `${Math.max(8, top)}px`;
    }

    function attachTooltipHandlers(canvas, resolveHoverEntry, formatValue) {
        if (canvas.dataset.tooltipBound === '1') {
            return;
        }

        canvas.addEventListener('mousemove', (event) => {
            const entry = resolveHoverEntry(event);
            if (!entry) {
                hideTooltip(canvas);
                return;
            }

            if (canvas._activeIndex !== entry.index) {
                canvas._activeIndex = entry.index;
                if (typeof canvas._rerender === 'function') {
                    canvas._rerender();
                }
            }

            showTooltip(canvas, entry, event, formatValue);
        });

        canvas.addEventListener('mouseleave', () => hideTooltip(canvas));
        canvas.addEventListener('blur', () => hideTooltip(canvas));
        canvas.dataset.tooltipBound = '1';
    }

    function baseSetup(canvas) {
        const dpr = window.devicePixelRatio || 1;
        const width = canvas.clientWidth || 600;
        const height = canvas.clientHeight || 260;
        canvas.width = Math.round(width * dpr);
        canvas.height = Math.round(height * dpr);

        const ctx = canvas.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, width, height);

        return { ctx, width, height };
    }

    function drawAxes(ctx, width, height, left, top, right, bottom, ticks, maxValue, formatTick) {
        ctx.strokeStyle = '#dbe4ef';
        ctx.lineWidth = 1;
        ctx.fillStyle = '#64748b';
        ctx.font = '12px ui-sans-serif, system-ui, sans-serif';

        for (let i = 0; i <= ticks; i++) {
            const ratio = i / ticks;
            const y = top + (bottom - top) * ratio;
            ctx.beginPath();
            ctx.moveTo(left, y);
            ctx.lineTo(right, y);
            ctx.stroke();

            const value = maxValue * (1 - ratio);
            ctx.fillText(formatTick(value), 8, y + 4);
        }

        ctx.strokeStyle = '#94a3b8';
        ctx.beginPath();
        ctx.moveTo(left, top);
        ctx.lineTo(left, bottom);
        ctx.lineTo(right, bottom);
        ctx.stroke();
    }

    function drawRightAxis(ctx, width, height, right, top, bottom, ticks, maxValue, formatTick, color) {
        ctx.strokeStyle = '#94a3b8';
        ctx.beginPath();
        ctx.moveTo(right, top);
        ctx.lineTo(right, bottom);
        ctx.stroke();

        ctx.fillStyle = color;
        ctx.font = '12px ui-sans-serif, system-ui, sans-serif';
        ctx.textAlign = 'right';

        for (let i = 0; i <= ticks; i++) {
            const ratio = i / ticks;
            const y = top + (bottom - top) * ratio;
            const value = maxValue * (1 - ratio);
            ctx.fillText(formatTick(value), width - 8, y + 4);
        }

        ctx.textAlign = 'start';
    }

    function drawXLabels(ctx, labels, width, height, left, right, bottom) {
        if (!labels.length) {
            return;
        }

        ctx.fillStyle = '#64748b';
        ctx.font = '12px ui-sans-serif, system-ui, sans-serif';
        ctx.textAlign = 'center';

        const indexes = [...new Set([0, Math.floor((labels.length - 1) / 2), labels.length - 1])];
        indexes.forEach((index) => {
            const x = labels.length === 1
                ? (left + right) / 2
                : left + ((right - left) * index) / (labels.length - 1);
            ctx.fillText(labels[index], x, height - 10);
        });

        ctx.textAlign = 'start';
    }

    function fillRoundedRect(ctx, x, y, width, height, radius) {
        if (typeof ctx.roundRect === 'function') {
            ctx.beginPath();
            ctx.roundRect(x, y, width, height, radius);
            ctx.fill();

            return;
        }

        const safeRadius = Math.min(radius, width / 2, height / 2);

        ctx.beginPath();
        ctx.moveTo(x + safeRadius, y);
        ctx.lineTo(x + width - safeRadius, y);
        ctx.quadraticCurveTo(x + width, y, x + width, y + safeRadius);
        ctx.lineTo(x + width, y + height - safeRadius);
        ctx.quadraticCurveTo(x + width, y + height, x + width - safeRadius, y + height);
        ctx.lineTo(x + safeRadius, y + height);
        ctx.quadraticCurveTo(x, y + height, x, y + height - safeRadius);
        ctx.lineTo(x, y + safeRadius);
        ctx.quadraticCurveTo(x, y, x + safeRadius, y);
        ctx.closePath();
        ctx.fill();
    }

    function renderLineChart(canvasId, values, labels, options) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) {
            return;
        }

        attachTooltipHandlers(
            canvas,
            (event) => {
                const state = canvas._chartState;
                if (!state || !state.points.length) {
                    return null;
                }

                const rect = canvas.getBoundingClientRect();
                const x = event.clientX - rect.left;
                const y = event.clientY - rect.top;

                if (x < state.left || x > state.right || y < state.top || y > state.bottom) {
                    return null;
                }

                const nearestPoint = state.points.reduce((closest, point, index) => {
                    const distance = Math.abs(point.x - x);
                    if (closest === null || distance < closest.distance) {
                        return { index, point, distance };
                    }

                    return closest;
                }, null);

                if (!nearestPoint) {
                    return null;
                }

                return {
                    index: nearestPoint.index,
                    date: state.dates[nearestPoint.index],
                    value: state.values[nearestPoint.index],
                    tooltipY: Math.max(state.top + 8, nearestPoint.point.y - 6),
                };
            },
            options.formatTooltipValue
        );

        const render = () => {
            const { ctx, width, height } = baseSetup(canvas);
            const left = 54;
            const top = 12;
            const right = width - 14;
            const bottom = height - 34;
            const maxValue = Math.max(1, ...values);

            drawAxes(ctx, width, height, left, top, right, bottom, 4, maxValue, options.formatTick);
            drawXLabels(ctx, labels, width, height, left, right, bottom);

            if (!values.length) {
                return;
            }

            const gradient = ctx.createLinearGradient(0, top, 0, bottom);
            gradient.addColorStop(0, options.areaTop);
            gradient.addColorStop(1, options.areaBottom);

            const points = values.map((value, index) => {
                const x = values.length === 1
                    ? (left + right) / 2
                    : left + ((right - left) * index) / (values.length - 1);
                const y = bottom - ((bottom - top) * value) / maxValue;
                return { x, y };
            });

            ctx.beginPath();
            ctx.moveTo(points[0].x, bottom);
            points.forEach((point) => ctx.lineTo(point.x, point.y));
            ctx.lineTo(points[points.length - 1].x, bottom);
            ctx.closePath();
            ctx.fillStyle = gradient;
            ctx.fill();

            ctx.beginPath();
            ctx.moveTo(points[0].x, points[0].y);
            points.forEach((point) => ctx.lineTo(point.x, point.y));
            ctx.strokeStyle = options.stroke;
            ctx.lineWidth = 3;
            ctx.stroke();

            const activeIndex = Number.isInteger(canvas._activeIndex) ? canvas._activeIndex : null;
            if (activeIndex !== null && points[activeIndex]) {
                const activePoint = points[activeIndex];

                ctx.beginPath();
                ctx.moveTo(activePoint.x, top);
                ctx.lineTo(activePoint.x, bottom);
                ctx.strokeStyle = 'rgba(100, 116, 139, 0.32)';
                ctx.lineWidth = 1;
                ctx.stroke();
            }

            ctx.fillStyle = options.stroke;
            points.forEach((point) => {
                ctx.beginPath();
                ctx.arc(point.x, point.y, 3, 0, Math.PI * 2);
                ctx.fill();
            });

            if (activeIndex !== null && points[activeIndex]) {
                const activePoint = points[activeIndex];

                ctx.beginPath();
                ctx.arc(activePoint.x, activePoint.y, 6, 0, Math.PI * 2);
                ctx.fillStyle = '#fff';
                ctx.fill();

                ctx.beginPath();
                ctx.arc(activePoint.x, activePoint.y, 4, 0, Math.PI * 2);
                ctx.fillStyle = options.stroke;
                ctx.fill();
            }

            canvas._chartState = {
                left,
                top,
                right,
                bottom,
                points,
                values,
                dates: options.dates,
            };
        };

        canvas._rerender = render;
        charts.push(render);
        render();
    }

    function renderBarChart(canvasId, values, labels, options) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) {
            return;
        }

        attachTooltipHandlers(
            canvas,
            (event) => {
                const state = canvas._chartState;
                if (!state || !state.bars.length) {
                    return null;
                }

                const rect = canvas.getBoundingClientRect();
                const x = event.clientX - rect.left;
                const y = event.clientY - rect.top;

                const bar = state.bars.find((item) => (
                    x >= item.x &&
                    x <= item.x + item.width &&
                    y >= item.y &&
                    y <= item.bottom
                ));

                if (!bar) {
                    return null;
                }

                return {
                    index: bar.index,
                    date: state.dates[bar.index],
                    value: state.values[bar.index],
                    tooltipY: Math.max(state.top + 8, bar.y - 6),
                };
            },
            options.formatTooltipValue
        );

        const render = () => {
            const { ctx, width, height } = baseSetup(canvas);
            const left = 54;
            const top = 12;
            const right = width - 14;
            const bottom = height - 34;
            const maxValue = Math.max(1, ...values);

            drawAxes(ctx, width, height, left, top, right, bottom, 4, maxValue, options.formatTick);
            drawXLabels(ctx, labels, width, height, left, right, bottom);

            if (!values.length) {
                return;
            }

            const slotWidth = (right - left) / values.length;
            const barWidth = Math.max(6, Math.min(22, slotWidth * 0.56));
            const activeIndex = Number.isInteger(canvas._activeIndex) ? canvas._activeIndex : null;
            const bars = [];

            values.forEach((value, index) => {
                const x = left + slotWidth * index + (slotWidth - barWidth) / 2;
                const barHeight = ((bottom - top) * value) / maxValue;
                const y = bottom - barHeight;

                ctx.fillStyle = activeIndex === index ? options.hoverFill : options.fill;
                fillRoundedRect(ctx, x, y, barWidth, Math.max(2, barHeight), 8);

                bars.push({
                    index,
                    x,
                    y,
                    width: barWidth,
                    bottom,
                });
            });

            canvas._chartState = {
                left,
                top,
                right,
                bottom,
                bars,
                values,
                dates: options.dates,
            };
        };

        canvas._rerender = render;
        charts.push(render);
        render();
    }

    function renderComboChart(canvasId, barValues, lineValues, labels, options) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) {
            return;
        }

        attachTooltipHandlers(
            canvas,
            (event) => {
                const state = canvas._chartState;
                if (!state || !state.points.length) {
                    return null;
                }

                const rect = canvas.getBoundingClientRect();
                const x = event.clientX - rect.left;
                const y = event.clientY - rect.top;

                if (x < state.left || x > state.right || y < state.top || y > state.bottom) {
                    return null;
                }

                const index = state.points.length === 1
                    ? 0
                    : Math.max(0, Math.min(
                        state.points.length - 1,
                        Math.round(((x - state.left) / (state.right - state.left)) * (state.points.length - 1))
                    ));

                return {
                    index,
                    date: state.dates[index],
                    paymentCount: state.barValues[index],
                    revenueRub: state.lineValues[index],
                    tooltipY: Math.max(
                        state.top + 8,
                        Math.min(state.bars[index].y, state.points[index].y) - 6
                    ),
                };
            },
            options.formatTooltipValue
        );

        const render = () => {
            const { ctx, width, height } = baseSetup(canvas);
            const left = 54;
            const top = 12;
            const right = width - 70;
            const bottom = height - 34;
            const maxBarValue = Math.max(1, ...barValues);
            const maxLineValue = Math.max(1, ...lineValues);

            drawAxes(ctx, width, height, left, top, right, bottom, 4, maxBarValue, options.barFormatTick);
            drawRightAxis(ctx, width, height, right, top, bottom, 4, maxLineValue, options.lineFormatTick, options.lineStroke);
            drawXLabels(ctx, labels, width, height, left, right, bottom);

            if (!barValues.length) {
                return;
            }

            const slotWidth = (right - left) / barValues.length;
            const barWidth = Math.max(6, Math.min(22, slotWidth * 0.5));
            const activeIndex = Number.isInteger(canvas._activeIndex) ? canvas._activeIndex : null;
            const bars = [];

            barValues.forEach((value, index) => {
                const x = left + slotWidth * index + (slotWidth - barWidth) / 2;
                const barHeight = ((bottom - top) * value) / maxBarValue;
                const y = bottom - barHeight;

                ctx.fillStyle = activeIndex === index ? options.hoverBarFill : options.barFill;
                fillRoundedRect(ctx, x, y, barWidth, Math.max(2, barHeight), 8);

                bars.push({
                    x,
                    y,
                    width: barWidth,
                    bottom,
                });
            });

            const points = lineValues.map((value, index) => {
                const x = lineValues.length === 1
                    ? (left + right) / 2
                    : left + ((right - left) * index) / (lineValues.length - 1);
                const y = bottom - ((bottom - top) * value) / maxLineValue;

                return { x, y };
            });

            ctx.beginPath();
            ctx.moveTo(points[0].x, points[0].y);
            points.forEach((point) => ctx.lineTo(point.x, point.y));
            ctx.strokeStyle = options.lineStroke;
            ctx.lineWidth = 3;
            ctx.stroke();

            if (activeIndex !== null && points[activeIndex]) {
                const activePoint = points[activeIndex];

                ctx.beginPath();
                ctx.moveTo(activePoint.x, top);
                ctx.lineTo(activePoint.x, bottom);
                ctx.strokeStyle = 'rgba(100, 116, 139, 0.32)';
                ctx.lineWidth = 1;
                ctx.stroke();
            }

            ctx.fillStyle = options.lineStroke;
            points.forEach((point) => {
                ctx.beginPath();
                ctx.arc(point.x, point.y, 3, 0, Math.PI * 2);
                ctx.fill();
            });

            if (activeIndex !== null && points[activeIndex]) {
                const activePoint = points[activeIndex];

                ctx.beginPath();
                ctx.arc(activePoint.x, activePoint.y, 6, 0, Math.PI * 2);
                ctx.fillStyle = '#fff';
                ctx.fill();

                ctx.beginPath();
                ctx.arc(activePoint.x, activePoint.y, 4, 0, Math.PI * 2);
                ctx.fillStyle = options.lineStroke;
                ctx.fill();
            }

            canvas._chartState = {
                left,
                top,
                right,
                bottom,
                bars,
                points,
                barValues,
                lineValues,
                dates: options.dates,
            };
        };

        canvas._rerender = render;
        charts.push(render);
        render();
    }

    renderBarChart('subscriptionsChart', chartData.subscriptionsDaily, chartData.labels, {
        fill: '#2563eb',
        hoverFill: '#1d4ed8',
        formatTick: (value) => Math.round(value).toString(),
        formatTooltipValue: (entry) => `${Math.round(entry.value)} шт.`,
        dates: chartData.dates,
    });

    renderComboChart('usersIncomeChart', chartData.estimatedDailyIncomeRub, chartData.usersTotalDaily, chartData.labels, {
        barFill: '#f43f5e',
        hoverBarFill: '#e11d48',
        lineStroke: '#7c3aed',
        barFormatTick: (value) => moneyFormatter.format(value),
        lineFormatTick: (value) => Math.round(value).toString(),
        formatTooltipValue: (entry) => `${moneyFormatter.format(entry.paymentCount)} ₽/день • ${Math.round(entry.revenueRub)} пользователей`,
        dates: chartData.dates,
    });

    renderComboChart('paymentsRevenueChart', chartData.paymentsCountDaily, chartData.revenueDailyRub, chartData.labels, {
        barFill: '#0f766e',
        hoverBarFill: '#0f5f5b',
        lineStroke: '#ea580c',
        barFormatTick: (value) => Math.round(value).toString(),
        lineFormatTick: (value) => moneyFormatter.format(value),
        formatTooltipValue: (entry) => `${Math.round(entry.paymentCount)} шт. • ${moneyFormatter.format(entry.revenueRub)} ₽`,
        dates: chartData.dates,
    });

    let resizeTimer = null;
    window.addEventListener('resize', () => {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(() => {
            document.querySelectorAll('.chart-tooltip').forEach((tooltip) => {
                tooltip.classList.remove('is-visible');
            });
            document.querySelectorAll('.chart-shell canvas').forEach((canvas) => {
                canvas._activeIndex = null;
            });
            charts.forEach((render) => render());
        }, 100);
    });
});
</script>
@endsection
