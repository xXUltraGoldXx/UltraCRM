<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Icon from './Icon.vue';
import { COLOR_HEX } from '../fieldTypes';
import { bcp47Locale } from '../i18n';

const props = defineProps({
    weekStart: { type: Date, required: true },   // Montag 00:00 der gewuenschten Woche
    items: { type: Array, default: () => [] },    // CalendarItem[]
});
const emit = defineEmits(['select-item', 'select-slot']);
const { locale } = useI18n();

// Modul #11: siehe CalendarMonthGrid.vue -- identisches Muster (Intl statt Hardcode).
const WEEKDAYS = computed(() => {
    void locale.value;
    const fmt = new Intl.DateTimeFormat(bcp47Locale(), { weekday: 'short' });
    const base = new Date(2024, 0, 1);
    return Array.from({ length: 7 }, (_, i) => fmt.format(addDays(base, i)));
});
const HOURS = Array.from({ length: 24 }, (_, h) => h);
const HOUR_PX = 44;

function addDays(d, n) {
    const r = new Date(d);
    r.setDate(r.getDate() + n);
    return r;
}
function startOfDay(d) {
    return new Date(d.getFullYear(), d.getMonth(), d.getDate());
}
function sameDay(a, b) {
    return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}
function isToday(d) {
    return sameDay(d, new Date());
}

const days = computed(() => Array.from({ length: 7 }, (_, i) => addDays(props.weekStart, i)));

const allDayItems = computed(() =>
    props.items.filter((it) => it.allDay).sort((a, b) => new Date(a.startsAt) - new Date(b.startsAt))
);
const timedItems = computed(() => props.items.filter((it) => !it.allDay));

function itemsForDay(day) {
    const dayStart = startOfDay(day);
    const dayEnd = addDays(dayStart, 1);
    return {
        allDay: allDayItems.value.filter((it) => new Date(it.startsAt) < dayEnd && new Date(it.endsAt) > dayStart),
        timed: timedItems.value
            .filter((it) => new Date(it.startsAt) < dayEnd && new Date(it.endsAt) > dayStart)
            .map((it) => {
                const s = Math.max(new Date(it.startsAt), dayStart);
                const e = Math.min(new Date(it.endsAt), dayEnd);
                const top = ((s - dayStart) / 3600000) * HOUR_PX;
                const height = Math.max(((e - s) / 3600000) * HOUR_PX, 20);
                return { it, top, height };
            }),
    };
}

function fmtTime(iso) {
    return new Date(iso).toLocaleTimeString(bcp47Locale(), { hour: '2-digit', minute: '2-digit' });
}

function onSlotClick(day, hour) {
    const d = new Date(day);
    d.setHours(hour, 0, 0, 0);
    emit('select-slot', d);
}
</script>

<template>
    <div class="week-grid">
        <div class="wg-head">
            <div class="wg-gutter"></div>
            <div v-for="(day, i) in days" :key="i" class="wg-daycol-head" :class="{ today: isToday(day) }">
                <span class="wg-weekday">{{ WEEKDAYS[i] }}</span>
                <span class="wg-daynum">{{ day.getDate() }}.{{ day.getMonth() + 1 }}.</span>
            </div>
        </div>

        <div class="wg-allday">
            <div class="wg-gutter wg-gutter-label">{{ $t('calendar.allDayLabel') }}</div>
            <div v-for="(day, i) in days" :key="i" class="wg-allday-col">
                <button v-for="it in itemsForDay(day).allDay" :key="it.id" class="wg-allday-item"
                    :style="{ borderColor: COLOR_HEX[it.color] || COLOR_HEX.blue }"
                    :title="it.title" @click="emit('select-item', it)">{{ it.title }}</button>
            </div>
        </div>

        <div class="wg-scroll">
            <div class="wg-hours">
                <div v-for="h in HOURS" :key="h" class="wg-hour-label" :style="{ height: HOUR_PX + 'px' }">
                    {{ String(h).padStart(2, '0') }}:00
                </div>
            </div>
            <div v-for="(day, i) in days" :key="i" class="wg-daycol" :class="{ today: isToday(day) }">
                <div v-for="h in HOURS" :key="h" class="wg-slot" :style="{ height: HOUR_PX + 'px' }"
                    @click="onSlotClick(day, h)"></div>
                <button v-for="({ it, top, height }) in itemsForDay(day).timed" :key="it.id" class="wg-item"
                    :style="{ top: top + 'px', height: height + 'px', borderColor: COLOR_HEX[it.color] || COLOR_HEX.blue }"
                    :title="it.title" @click.stop="emit('select-item', it)">
                    <b>{{ it.title }}</b>
                    <span>{{ fmtTime(it.startsAt) }}–{{ fmtTime(it.endsAt) }}</span>
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.week-grid { display: flex; flex-direction: column; height: 100%; }
.wg-gutter { width: 52px; flex-shrink: 0; }
.wg-head { display: flex; padding-bottom: 8px; border-bottom: 1px solid var(--line); }
.wg-daycol-head { flex: 1; text-align: center; padding: 4px 0; }
.wg-daycol-head.today .wg-daynum { color: var(--accent); }
.wg-weekday { display: block; font-size: 0.72rem; color: var(--ink-soft); text-transform: uppercase; letter-spacing: 0.04em; }
.wg-daynum { display: block; font-size: 0.88rem; font-weight: 700; }

.wg-allday { display: flex; padding: 6px 0; border-bottom: 1px solid var(--line); min-height: 20px; }
.wg-gutter-label { font-size: 0.68rem; color: var(--ink-soft); display: flex; align-items: center; }
.wg-allday-col { flex: 1; display: flex; flex-direction: column; gap: 3px; padding: 0 3px; }
.wg-allday-item {
    background: var(--surface-2); border: 1px solid var(--line); border-left: 3px solid;
    border-radius: 5px; padding: 2px 7px; font-size: 0.72rem; text-align: left; cursor: pointer; color: var(--ink);
}
.wg-allday-item:hover { background: var(--line); }

.wg-scroll { display: flex; flex: 1; overflow-y: auto; }
.wg-hours { width: 52px; flex-shrink: 0; }
.wg-hour-label { font-size: 0.68rem; color: var(--ink-soft); text-align: right; padding-right: 8px; box-sizing: border-box; transform: translateY(-6px); }
.wg-daycol { flex: 1; position: relative; border-left: 1px solid var(--line); }
.wg-daycol.today { background: rgba(59,130,246,0.04); }
.wg-slot { border-bottom: 1px solid var(--line); cursor: pointer; }
.wg-slot:hover { background: var(--surface-2); }
.wg-item {
    position: absolute; left: 2px; right: 2px;
    background: var(--surface); border: 1px solid var(--line); border-left: 3px solid;
    border-radius: 6px; padding: 3px 6px; overflow: hidden;
    text-align: left; cursor: pointer; z-index: 2;
    display: flex; flex-direction: column; gap: 1px;
}
.wg-item:hover { background: var(--line); }
.wg-item b { font-size: 0.72rem; color: var(--ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.wg-item span { font-size: 0.64rem; color: var(--ink-soft); }
</style>
