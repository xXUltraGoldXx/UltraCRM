<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import api from '../api';
import { FIELD_TYPES, fieldMeta, newField, TEMPLATE_COLORS, COLOR_HEX, TEMPLATE_ICONS } from '../fieldTypes';
import Icon from '../components/Icon.vue';

const route = useRoute();
const router = useRouter();
const { t } = useI18n();
const isNew = route.params.id === 'neu';

const tpl = ref({ name: '', icon: 'file', color: 'blue', description: '', schema: [], active: true });
const selectedId = ref(null);
const saving = ref(false);
const saved = ref(false);
const dragType = ref(null);         // Typ aus der Palette
const dragIndex = ref(null);        // umsortieren innerhalb Canvas
const iconMenuOpen = ref(false);

const GROUP_LABEL_KEYS = { layout: 'templateDesigner.groupLayout', basis: 'templateDesigner.groupBasis', speziell: 'templateDesigner.groupSpeziell' };

const selectedField = computed(() =>
    tpl.value.schema.find((f) => f.id === selectedId.value) || null
);

onMounted(async () => {
    if (!isNew) {
        const { data } = await api.get(`/form_templates/${route.params.id}`);
        tpl.value = { ...data, schema: data.schema || [] };
    }
});

// ---- Palette → Canvas ----
function onPaletteDragStart(type) { dragType.value = type; dragIndex.value = null; }

function onCanvasDrop(e) {
    e.preventDefault();
    const dropIdx = dropIndexFromEvent(e);
    if (dragType.value) {
        const f = newField(dragType.value);
        tpl.value.schema.splice(dropIdx, 0, f);
        selectedId.value = f.id;
        dragType.value = null;
    } else if (dragIndex.value !== null) {
        const [moved] = tpl.value.schema.splice(dragIndex.value, 1);
        const target = dropIdx > dragIndex.value ? dropIdx - 1 : dropIdx;
        tpl.value.schema.splice(target, 0, moved);
        dragIndex.value = null;
    }
}

function dropIndexFromEvent(e) {
    const items = [...e.currentTarget.querySelectorAll('.canvas-field')];
    for (let i = 0; i < items.length; i++) {
        const r = items[i].getBoundingClientRect();
        if (e.clientY < r.top + r.height / 2) return i;
    }
    return items.length;
}

function onFieldDragStart(i) { dragIndex.value = i; dragType.value = null; }

function removeField(id) {
    tpl.value.schema = tpl.value.schema.filter((f) => f.id !== id);
    if (selectedId.value === id) selectedId.value = null;
}

function addOption(f) { f.options.push(t('templateDesigner.optionDefault')); }
function removeOption(f, i) { f.options.splice(i, 1); }

async function save() {
    if (!tpl.value.name.trim()) { tpl.value.name = t('templateDesigner.unnamedDefault'); }
    saving.value = true;
    try {
        const payload = {
            name: tpl.value.name,
            icon: tpl.value.icon,
            color: tpl.value.color,
            description: tpl.value.description,
            schema: tpl.value.schema,
            active: tpl.value.active,
        };
        if (isNew) {
            const { data } = await api.post('/form_templates', payload, { headers: { 'Content-Type': 'application/ld+json' } });
            router.replace({ name: 'template-designer', params: { id: data.id } });
        } else {
            await api.patch(`/form_templates/${route.params.id}`, payload, { headers: { 'Content-Type': 'application/merge-patch+json' } });
        }
        saved.value = true;
        setTimeout(() => (saved.value = false), 2000);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="designer">
        <!-- Kopfzeile -->
        <div class="d-head card">
            <button class="icon-btn" :title="$t('templateDesigner.backTitle')" @click="router.push({ name: 'templates' })"><Icon name="back" :size="18" /></button>
            <div class="icon-pick">
                <button class="icon-trigger" :style="{ color: COLOR_HEX[tpl.color] }" @click="iconMenuOpen = !iconMenuOpen">
                    <Icon :name="tpl.icon" :size="20" />
                </button>
                <div v-if="iconMenuOpen" class="icon-menu card">
                    <button v-for="ic in TEMPLATE_ICONS" :key="ic" class="icon-choice"
                        :class="{ active: tpl.icon === ic }" @click="tpl.icon = ic; iconMenuOpen = false">
                        <Icon :name="ic" :size="18" />
                    </button>
                </div>
            </div>
            <input v-model="tpl.name" class="name-input" :placeholder="$t('templateDesigner.namePlaceholder')">
            <div class="color-dots">
                <button v-for="c in TEMPLATE_COLORS" :key="c" class="color-dot"
                    :class="{ active: tpl.color === c }" :style="{ background: COLOR_HEX[c] }"
                    :title="$t('templateDesigner.colorTitle')" @click="tpl.color = c"></button>
            </div>
            <span v-if="saved" class="saved-hint"><Icon name="check" :size="15" /> {{ $t('templateDesigner.saved') }}</span>
            <button class="btn" :disabled="saving" @click="save">{{ saving ? $t('common.saving') : $t('common.save') }}</button>
        </div>

        <div class="d-body">
            <!-- Palette -->
            <aside class="palette card">
                <h3>{{ $t('templateDesigner.paletteTitle') }}</h3>
                <p class="palette-hint">{{ $t('templateDesigner.paletteHint') }}</p>
                <div v-for="grp in ['layout', 'basis', 'speziell']" :key="grp" class="palette-group">
                    <div class="palette-group-title">{{ $t(GROUP_LABEL_KEYS[grp]) }}</div>
                    <div v-for="ft in FIELD_TYPES.filter((f) => f.group === grp)" :key="ft.type"
                        class="palette-item" draggable="true"
                        @dragstart="onPaletteDragStart(ft.type)">
                        <Icon :name="ft.icon" :size="17" /><span>{{ $t(ft.labelKey) }}</span>
                    </div>
                </div>
            </aside>

            <!-- Canvas -->
            <section class="canvas card" @dragover.prevent @drop="onCanvasDrop">
                <div v-if="!tpl.schema.length" class="canvas-empty">
                    <div class="canvas-empty-icon"><Icon name="file-plus" :size="28" /></div>
                    <p>{{ $t('templateDesigner.canvasEmpty') }}</p>
                </div>
                <div v-for="(f, i) in tpl.schema" :key="f.id"
                    class="canvas-field" :class="{ selected: selectedId === f.id, [f.width]: true }"
                    draggable="true" @dragstart="onFieldDragStart(i)"
                    @click="selectedId = f.id">
                    <div class="cf-head">
                        <span class="cf-type"><Icon :name="fieldMeta(f.type).icon" :size="15" /></span>
                        <span class="cf-label">{{ f.type === 'heading' ? $t('templateDesigner.headingDefault') : (f.label || $t(fieldMeta(f.type).labelKey)) }}<em v-if="f.required">*</em></span>
                        <button class="cf-del" :title="$t('templateDesigner.fieldRemoveTitle')" @click.stop="removeField(f.id)"><Icon name="x" :size="14" /></button>
                    </div>
                    <!-- Vorschau je Typ -->
                    <div class="cf-preview">
                        <div v-if="f.type === 'heading'" class="pv-heading">{{ f.label || $t('templateDesigner.headingDefault') }}</div>
                        <input v-else-if="['text','number','date','time'].includes(f.type)" class="pv-input" disabled
                            :placeholder="f.placeholder || $t(fieldMeta(f.type).labelKey)">
                        <textarea v-else-if="f.type === 'textarea'" class="pv-input" disabled rows="2" :placeholder="f.placeholder"></textarea>
                        <select v-else-if="f.type === 'select'" class="pv-input" disabled>
                            <option>{{ f.options?.[0] || $t('templateDesigner.previewOption') }}</option>
                        </select>
                        <label v-else-if="f.type === 'checkbox'" class="pv-check"><input type="checkbox" disabled> {{ f.label }}</label>
                        <div v-else-if="f.type === 'customer'" class="pv-special"><Icon name="user" :size="15" /> {{ $t('templateDesigner.previewCustomer') }}</div>
                        <div v-else-if="f.type === 'spareparts'" class="pv-special"><Icon name="tool" :size="15" /> {{ $t('templateDesigner.previewSpareparts') }}</div>
                        <div v-else-if="f.type === 'signature'" class="pv-sign"><Icon name="signature" :size="16" /> {{ $t('templateDesigner.previewSignature') }}</div>
                    </div>
                </div>
            </section>

            <!-- Eigenschaften -->
            <aside class="props card">
                <h3>{{ $t('templateDesigner.propsTitle') }}</h3>
                <div v-if="!selectedField" class="props-empty">{{ $t('templateDesigner.propsEmpty') }}</div>
                <div v-else class="props-form">
                    <label>{{ $t('templateDesigner.propsLabel') }}
                        <input v-model="selectedField.label" class="input" :placeholder="$t('templateDesigner.propsLabelPlaceholder')">
                    </label>
                    <label v-if="['text','textarea','number'].includes(selectedField.type)">{{ $t('templateDesigner.propsPlaceholder') }}
                        <input v-model="selectedField.placeholder" class="input" :placeholder="$t('templateDesigner.propsPlaceholderHint')">
                    </label>
                    <label v-if="selectedField.type !== 'heading'">{{ $t('templateDesigner.propsWidth') }}
                        <select v-model="selectedField.width" class="input">
                            <option value="full">{{ $t('templateDesigner.propsWidthFull') }}</option>
                            <option value="half">{{ $t('templateDesigner.propsWidthHalf') }}</option>
                        </select>
                    </label>
                    <label v-if="selectedField.type === 'select'">{{ $t('templateDesigner.propsOptions') }}
                        <div class="opt-list">
                            <div v-for="(o, oi) in selectedField.options" :key="oi" class="opt-row">
                                <input v-model="selectedField.options[oi]" class="input">
                                <button class="cf-del" :title="$t('templateDesigner.propsOptionRemoveTitle')" @click="removeOption(selectedField, oi)"><Icon name="x" :size="14" /></button>
                            </div>
                            <button class="btn-ghost btn add-opt" @click="addOption(selectedField)"><Icon name="plus" :size="15" /> {{ $t('templateDesigner.propsOptionAdd') }}</button>
                        </div>
                    </label>
                    <label v-if="selectedField.type !== 'heading'" class="check-row">
                        <input type="checkbox" v-model="selectedField.required"> {{ $t('templateDesigner.propsRequired') }}
                    </label>
                </div>
            </aside>
        </div>
    </div>
</template>

<style scoped>
.designer { display: flex; flex-direction: column; gap: 16px; height: calc(100vh - 120px); }
.d-head {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 12px 16px;
}
.icon-pick { position: relative; }
.icon-trigger {
    background: var(--surface-2);
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 9px;
    cursor: pointer;
    display: flex;
}
.icon-trigger:hover { border-color: var(--accent); }
.icon-menu {
    position: absolute;
    top: 46px;
    left: 0;
    z-index: 30;
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 4px;
    padding: 8px;
}
.icon-choice {
    background: none;
    border: 1px solid transparent;
    border-radius: 8px;
    padding: 8px;
    cursor: pointer;
    color: var(--ink-soft);
    display: flex;
}
.icon-choice:hover { background: var(--surface-2); color: var(--ink); }
.icon-choice.active { border-color: var(--accent); color: var(--accent); }
.name-input {
    flex: 1;
    background: none;
    border: none;
    outline: none;
    color: var(--ink);
    font-size: 1.1rem;
    font-weight: 700;
}
.color-dots { display: flex; gap: 6px; }
.color-dot {
    width: 20px; height: 20px;
    border-radius: 50%;
    border: 2px solid transparent;
    cursor: pointer;
}
.color-dot.active { border-color: var(--ink); }
.saved-hint { color: var(--green); font-size: 0.85rem; font-weight: 600; }

.d-body { display: grid; grid-template-columns: 210px 1fr 260px; gap: 16px; flex: 1; min-height: 0; }
.palette, .canvas, .props { padding: 16px; overflow-y: auto; }
.palette h3, .props h3 { font-size: 0.95rem; margin-bottom: 4px; }
.palette-hint { color: var(--ink-soft); font-size: 0.76rem; margin-bottom: 14px; }
.palette-group-title {
    font-size: 0.68rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--ink-soft);
    margin: 12px 0 6px;
}
.palette-item {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 9px 11px;
    background: var(--surface-2);
    border: 1px solid var(--line);
    border-radius: 9px;
    margin-bottom: 6px;
    cursor: grab;
    font-size: 0.85rem;
    color: var(--ink);
    transition: border-color 0.15s;
}
.palette-item :deep(svg) { color: var(--ink-soft); }
.palette-item:hover { border-color: var(--accent); }
.palette-item:hover :deep(svg) { color: var(--accent); }
.palette-item:active { cursor: grabbing; }

.canvas {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-content: flex-start;
}
.canvas-empty {
    margin: auto;
    text-align: center;
    color: var(--ink-soft);
    font-size: 0.9rem;
    line-height: 1.7;
}
.canvas-empty-icon {
    width: 60px; height: 60px;
    border-radius: 14px;
    background: var(--surface-2);
    color: var(--accent);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 14px;
}
.canvas-field {
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 12px 14px;
    background: var(--surface-2);
    cursor: grab;
    transition: border-color 0.15s;
    width: 100%;
}
.canvas-field.selected { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(59,130,246,0.15); }
.canvas-field.half { width: calc(50% - 5px); }
.cf-head { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.cf-type { display: flex; color: var(--ink-soft); }
.cf-label { flex: 1; font-size: 0.85rem; font-weight: 600; }
.cf-label em { color: var(--red); font-style: normal; }
.cf-del {
    background: none;
    border: none;
    color: var(--ink-soft);
    cursor: pointer;
    font-size: 0.85rem;
    padding: 2px 6px;
    border-radius: 6px;
}
.cf-del:hover { background: var(--line); color: var(--red); }
.pv-input {
    width: 100%;
    padding: 8px 11px;
    border-radius: 8px;
    border: 1px dashed var(--line);
    background: var(--surface);
    color: var(--ink-soft);
    font-size: 0.85rem;
}
.pv-heading { font-weight: 700; font-size: 1rem; border-bottom: 2px solid var(--accent); padding-bottom: 4px; }
.pv-check, .pv-special, .pv-sign { font-size: 0.85rem; color: var(--ink-soft); }
.pv-special { display: flex; align-items: center; gap: 8px; }
.pv-sign {
    border: 1px dashed var(--line);
    border-radius: 8px;
    padding: 16px;
    text-align: center;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}
.saved-hint { display: flex; align-items: center; gap: 5px; }

.props-empty { color: var(--ink-soft); font-size: 0.85rem; }
.props-form { display: flex; flex-direction: column; gap: 14px; }
.props-form label { display: flex; flex-direction: column; gap: 6px; font-size: 0.8rem; font-weight: 600; color: var(--ink-soft); }
.check-row { flex-direction: row !important; align-items: center; gap: 8px; }
.opt-list { display: flex; flex-direction: column; gap: 6px; }
.opt-row { display: flex; gap: 6px; align-items: center; }
.add-opt { padding: 7px; font-size: 0.8rem; justify-content: center; }

@media (max-width: 1000px) {
    .d-body { grid-template-columns: 1fr; overflow-y: auto; }
    .designer { height: auto; }
}
</style>
