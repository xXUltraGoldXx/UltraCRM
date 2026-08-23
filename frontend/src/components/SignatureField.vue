<script setup>
import { ref, onMounted, watch } from 'vue';
import Icon from './Icon.vue';

const props = defineProps({ modelValue: { type: String, default: '' } });
const emit = defineEmits(['update:modelValue']);

const canvas = ref(null);
let ctx = null;
let drawing = false;
let hasContent = false;

function pos(e) {
    const r = canvas.value.getBoundingClientRect();
    const p = e.touches ? e.touches[0] : e;
    return { x: p.clientX - r.left, y: p.clientY - r.top };
}
function start(e) {
    drawing = true;
    const { x, y } = pos(e);
    ctx.beginPath();
    ctx.moveTo(x, y);
    e.preventDefault();
}
function move(e) {
    if (!drawing) return;
    const { x, y } = pos(e);
    ctx.lineTo(x, y);
    ctx.stroke();
    hasContent = true;
    e.preventDefault();
}
function end() {
    if (!drawing) return;
    drawing = false;
    if (hasContent) emit('update:modelValue', canvas.value.toDataURL('image/png'));
}
function clear() {
    ctx.clearRect(0, 0, canvas.value.width, canvas.value.height);
    hasContent = false;
    emit('update:modelValue', '');
}

onMounted(() => {
    const c = canvas.value;
    c.width = c.offsetWidth * 2;
    c.height = c.offsetHeight * 2;
    ctx = c.getContext('2d');
    ctx.scale(2, 2);
    ctx.strokeStyle = '#111';
    ctx.lineWidth = 1.6;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    if (props.modelValue) {
        const img = new Image();
        img.onload = () => ctx.drawImage(img, 0, 0, c.offsetWidth, c.offsetHeight);
        img.src = props.modelValue;
        hasContent = true;
    }
});
</script>

<template>
    <div class="sig">
        <canvas ref="canvas" class="sig-canvas"
            @mousedown="start" @mousemove="move" @mouseup="end" @mouseleave="end"
            @touchstart="start" @touchmove="move" @touchend="end"></canvas>
        <button class="sig-clear" :title="$t('signature.clearTitle')" @click="clear"><Icon name="trash" :size="14" /> {{ $t('signature.clear') }}</button>
    </div>
</template>

<style scoped>
.sig { position: relative; }
.sig-canvas {
    width: 100%;
    height: 130px;
    border: 1px dashed var(--line);
    border-radius: 10px;
    background: #fff;
    display: block;
    cursor: crosshair;
    touch-action: none;
}
.sig-clear {
    position: absolute;
    top: 8px; right: 8px;
    display: flex; align-items: center; gap: 5px;
    background: rgba(255,255,255,0.9);
    border: 1px solid var(--line);
    color: #555;
    font-size: 0.76rem;
    padding: 4px 9px;
    border-radius: 7px;
    cursor: pointer;
}
.sig-clear:hover { color: var(--red); }
</style>
