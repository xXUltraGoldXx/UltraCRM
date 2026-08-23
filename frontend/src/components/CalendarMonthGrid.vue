<script setup>
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import Icon from './Icon.vue';
import { COLOR_HEX } from '../fieldTypes';
import { bcp47Locale } from '../i18n';

const props = defineProps({
    monthDate: { type: Date, required: true },  // irgendein Tag im gewuenschten Monat
    items: { type: Array, default: () => [] },   // CalendarItem[] {id,title,startsAt,endsAt,allDay,color,kind}
    // Paket 2, Punkt 2: nur mit calendar.manage ueberhaupt draggable (die
    // Entscheidung, WER draggen darf, gehoert CalendarView, hier nur die
    // Weiche fuers Attribut).
    canManage: { type: Boolean, default: false },
});
const emit = defineEmits(['select-item', 'select-day', 'move-item']);
const { t, locale } = useI18n();

// Drag&Drop (natives HTML5-DnD, keine Lib): nur Termine (kind=appointment),
// Urlaubs-Items NIEMALS draggable -- sie gehoeren zu einer anderen Entity mit
// eigener Zustandsmaschine (siehe CalendarView::openEdit-Kommentar). Touch ist
// mit nativem HTML5-DnD NICHT abgedeckt (kein touchstart/-move-Aequivalent) --
// bewusst akzeptiert, siehe Auftrag; eine Touch-Loesung braeuchte eigene
// Pointer-Event-Gesten oder eine Lib, beides ausserhalb des Scopes hier.
function isDraggable(it) {
    return props.canManage && it.kind === 'appointment';
}
const draggingItem = ref(null);
function onItemDragStart(it, event) {
    if (!isDraggable(it)) return;
    draggingItem.value = it;
    event.dataTransfer.effectAllowed = 'move';
    // Firefox verlangt setData, um den Drag ueberhaupt zu starten -- der
    // eigentliche Datentransport laeuft aber ueber draggingItem (einfacher als
    // JSON aus dataTransfer zu lesen, das im dragover/drop teils nicht
    // zugreifbar ist).
    event.dataTransfer.setData('text/plain', String(it.id));
}
function onItemDragEnd() {
    draggingItem.value = null;
}
function onDayDrop(day, event) {
    event.preventDefault();
    if (!draggingItem.value) return;
    emit('move-item', { item: draggingItem.value, newDate: day.date });
    draggingItem.value = null;
}

// Modul #11: Wochentagsnamen ueber Intl.DateTimeFormat statt Hardcode-Array --
// 2024-01-01 ist ein Montag, dient nur als stabiler Referenztag fuer die
// Kurzform je Sprache. "locale.value" wird hier absichtlich gelesen (auch
// ohne es weiter zu verwenden), damit Vue diesen computed als Abhaengig von
// der Sprache erkennt und bei einem Sprachwechsel neu berechnet -- bcp47Locale()
// liest zwar denselben Wert, aber ueber einen Funktionsaufruf, der fuer die
// Dependency-Tracking-Analyse genauso zaehlt; die explizite Zeile macht die
// Abhaengigkeit zusaetzlich fuer Lesende sofort sichtbar.
const WEEKDAYS = computed(() => {
    void locale.value;
    const fmt = new Intl.DateTimeFormat(bcp47Locale(), { weekday: 'short' });
    const base = new Date(2024, 0, 1);
    return Array.from({ length: 7 }, (_, i) => fmt.format(addDays(base, i)));
});

function startOfDay(d) {
    return new Date(d.getFullYear(), d.getMonth(), d.getDate());
}
function addDays(d, n) {
    const r = new Date(d);
    r.setDate(r.getDate() + n);
    return r;
}
function sameDay(a, b) {
    return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}
function isToday(d) {
    return sameDay(d, new Date());
}

// 6x7-Raster: erster Montag VOR (oder am) 1. des Monats, danach 42 Tage am Stueck --
// so tauchen Termine ueber die Monatsgrenze hinweg automatisch im Nachbarmonat mit auf.
const gridStart = computed(() => {
    const first = new Date(props.monthDate.getFullYear(), props.monthDate.getMonth(), 1);
    const dow = (first.getDay() + 6) % 7; // Montag = 0
    return addDays(first, -dow);
});

const weeks = computed(() => {
    const out = [];
    let cursor = gridStart.value;
    for (let w = 0; w < 6; w++) {
        const days = [];
        for (let d = 0; d < 7; d++) {
            const date = cursor;
            const dayStart = startOfDay(date);
            const dayEnd = addDays(dayStart, 1);
            const dayItems = props.items.filter((it) => {
                const s = new Date(it.startsAt);
                const e = new Date(it.endsAt);
                return s < dayEnd && e > dayStart;
            }).sort((a, b) => new Date(a.startsAt) - new Date(b.startsAt));
            days.push({
                date,
                inMonth: date.getMonth() === props.monthDate.getMonth(),
                today: isToday(date),
                items: dayItems,
            });
            cursor = addDays(cursor, 1);
        }
        out.push(days);
    }
    return out;
});

function fmtTime(iso) {
    return new Date(iso).toLocaleTimeString(bcp47Locale(), { hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <div class="month-grid">
        <div class="mg-weekdays">
            <span v-for="w in WEEKDAYS" :key="w">{{ w }}</span>
        </div>
        <div class="mg-body">
            <div v-for="(week, wi) in weeks" :key="wi" class="mg-week">
                <div v-for="day in week" :key="day.date.toISOString()" class="mg-day"
                    :class="{ 'out-month': !day.inMonth, today: day.today, 'drop-target': draggingItem }"
                    @dragover.prevent @drop="onDayDrop(day, $event)">
                    <div class="mg-day-head">
                        <span class="mg-day-num">{{ day.date.getDate() }}</span>
                        <button class="mg-add" :title="t('calendar.addDayTitle')" @click="emit('select-day', day.date)">
                            <Icon name="plus" :size="12" />
                        </button>
                    </div>
                    <div class="mg-items">
                        <button v-for="it in day.items" :key="it.id" class="mg-item"
                            :class="{ draggable: isDraggable(it) }"
                            :draggable="isDraggable(it)"
                            :style="{ borderColor: COLOR_HEX[it.color] || COLOR_HEX.blue }"
                            :title="it.title" @click="emit('select-item', it)"
                            @dragstart="onItemDragStart(it, $event)" @dragend="onItemDragEnd">
                            <span class="mg-item-dot" :style="{ background: COLOR_HEX[it.color] || COLOR_HEX.blue }"></span>
                            <span v-if="!it.allDay" class="mg-item-time">{{ fmtTime(it.startsAt) }}</span>
                            <span class="mg-item-title">{{ it.title }}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.month-grid { display: flex; flex-direction: column; height: 100%; }
.mg-weekdays {
    display: grid; grid-template-columns: repeat(7, 1fr);
    padding: 0 2px 8px;
    color: var(--ink-soft); font-size: 0.76rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em;
}
.mg-weekdays span { text-align: center; }
.mg-body { display: flex; flex-direction: column; gap: 6px; flex: 1; }
.mg-week { display: grid; grid-template-columns: repeat(7, 1fr); gap: 6px; flex: 1; min-height: 0; }
.mg-day {
    background: var(--surface-2);
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 6px 7px;
    display: flex; flex-direction: column; gap: 4px;
    min-height: 90px;
    overflow: hidden;
}
.mg-day.out-month { opacity: 0.45; }
.mg-day.today { border-color: var(--accent); box-shadow: 0 0 0 2px rgba(59,130,246,0.15) inset; }
/* Paket 2, Punkt 2: Drag&Drop-Feedback -- jede Tageszelle ist waehrend eines
   Drags ein potenzielles Ziel (dragover greift ueberall), leichtes Highlight
   beim Ueberfliegen macht das sichtbar. */
.mg-day.drop-target:hover { border-color: var(--accent); background: rgba(59,130,246,0.08); }
.mg-day-head { display: flex; align-items: center; justify-content: space-between; }
.mg-day-num { font-size: 0.8rem; font-weight: 700; color: var(--ink-soft); }
.mg-day.today .mg-day-num { color: var(--accent); }
.mg-add {
    background: none; border: none; color: var(--ink-soft); cursor: pointer;
    width: 18px; height: 18px; border-radius: 5px; display: flex; align-items: center; justify-content: center;
    opacity: 0; transition: opacity 0.15s;
}
.mg-day:hover .mg-add { opacity: 1; }
.mg-add:hover { background: var(--line); color: var(--ink); }
.mg-items { display: flex; flex-direction: column; gap: 3px; overflow-y: auto; }
.mg-item {
    display: flex; align-items: center; gap: 5px;
    background: var(--surface);
    border: 1px solid var(--line);
    border-left: 3px solid;
    border-radius: 6px;
    padding: 3px 6px;
    text-align: left;
    cursor: pointer;
    font-size: 0.72rem;
    color: var(--ink);
    width: 100%;
}
.mg-item:hover { background: var(--line); }
.mg-item.draggable { cursor: grab; }
.mg-item.draggable:active { cursor: grabbing; }
.mg-item-dot { display: none; }
.mg-item-time { color: var(--ink-soft); flex-shrink: 0; font-variant-numeric: tabular-nums; }
.mg-item-title { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

@media (max-width: 720px) {
    .mg-weekdays span:nth-child(n) { font-size: 0.62rem; }
    .mg-day { min-height: 60px; padding: 4px 5px; }
    .mg-item-time { display: none; }
}
</style>
