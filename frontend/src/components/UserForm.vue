<script setup>
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import api from '../api';
import Icon from './Icon.vue';
import { PERMISSION_GROUPS } from '../permissions';

const props = defineProps({ user: { type: Object, default: null } });
const emit = defineEmits(['saved', 'close']);
const { t } = useI18n();

const isEdit = !!props.user?.id;
const form = ref({
    displayName: props.user?.displayName || '',
    username: props.user?.username || '',
    email: props.user?.email || '',
    plainPassword: '',
    role: props.user?.roles?.includes('ROLE_ADMIN') ? 'admin' : 'user',
    active: props.user?.active ?? true,
    permissions: [...(props.user?.permissions || [])],
    vacationDaysPerYear: props.user?.vacationDaysPerYear ?? 30,
});
const saving = ref(false);
const error = ref('');

const isAdmin = computed(() => form.value.role === 'admin');

function togglePerm(key) {
    const i = form.value.permissions.indexOf(key);
    if (i >= 0) form.value.permissions.splice(i, 1);
    else form.value.permissions.push(key);
}

async function save() {
    error.value = '';
    if (!form.value.displayName.trim() || !form.value.username.trim()) {
        error.value = t('userForm.errorRequired');
        return;
    }
    if (!isEdit && !form.value.plainPassword) {
        error.value = t('userForm.errorPassword');
        return;
    }
    saving.value = true;
    try {
        const payload = {
            displayName: form.value.displayName,
            username: form.value.username,
            email: form.value.email || null,
            roles: isAdmin.value ? ['ROLE_ADMIN'] : ['ROLE_USER'],
            permissions: isAdmin.value ? [] : form.value.permissions,
            active: form.value.active,
            vacationDaysPerYear: Number(form.value.vacationDaysPerYear) || 0,
        };
        if (form.value.plainPassword) payload.plainPassword = form.value.plainPassword;

        if (isEdit) {
            await api.patch(`/users/${props.user.id}`, payload, { headers: { 'Content-Type': 'application/merge-patch+json' } });
        } else {
            await api.post('/users', payload, { headers: { 'Content-Type': 'application/ld+json' } });
        }
        emit('saved');
    } catch (e) {
        error.value = e.response?.data?.detail || t('userForm.errorSave');
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="modal-backdrop" @click.self="emit('close')">
        <div class="modal card">
            <div class="modal-head">
                <h2>{{ isEdit ? $t('userForm.titleEdit') : $t('userForm.titleNew') }}</h2>
                <button class="icon-close" @click="emit('close')"><Icon name="x" :size="16" /></button>
            </div>

            <div class="modal-body">
                <div class="grid2">
                    <label>{{ $t('userForm.fullName') }}
                        <input v-model="form.displayName" class="input" :placeholder="$t('userForm.fullNamePlaceholder')">
                    </label>
                    <label>{{ $t('userForm.username') }}
                        <input v-model="form.username" class="input" :placeholder="$t('userForm.usernamePlaceholder')">
                    </label>
                    <label>{{ $t('userForm.email') }}
                        <input v-model="form.email" class="input" type="email">
                    </label>
                    <label>{{ isEdit ? $t('userForm.passwordNew') : $t('userForm.password') }}
                        <input v-model="form.plainPassword" class="input" type="password" :placeholder="isEdit ? $t('userForm.passwordKeepPlaceholder') : ''">
                    </label>
                    <label>{{ $t('userForm.vacationDays') }}
                        <input v-model.number="form.vacationDaysPerYear" class="input" type="number" min="0" max="60">
                    </label>
                </div>

                <div class="role-choice">
                    <span class="section-label">{{ $t('userForm.roleLabel') }}</span>
                    <div class="role-tabs">
                        <button :class="{ active: form.role === 'user' }" @click="form.role = 'user'">
                            <b>{{ $t('userForm.roleUserTitle') }}</b><small>{{ $t('userForm.roleUserHint') }}</small>
                        </button>
                        <button :class="{ active: form.role === 'admin' }" @click="form.role = 'admin'">
                            <b>{{ $t('userForm.roleAdminTitle') }}</b><small>{{ $t('userForm.roleAdminHint') }}</small>
                        </button>
                    </div>
                </div>

                <div v-if="!isAdmin" class="perms">
                    <span class="section-label">{{ $t('userForm.permissionsLabel') }}</span>
                    <div v-for="g in PERMISSION_GROUPS" :key="g.moduleKey" class="perm-group">
                        <div class="perm-group-title">{{ $t(g.moduleKey) }}</div>
                        <label v-for="item in g.items" :key="item.key" class="perm-item">
                            <input type="checkbox" :checked="form.permissions.includes(item.key)" @change="togglePerm(item.key)">
                            {{ $t(item.labelKey) }}
                        </label>
                    </div>
                </div>
                <p v-else class="admin-note">
                    <Icon name="shield" :size="16" /> {{ $t('userForm.adminNote') }}
                </p>
            </div>

            <p v-if="error" class="form-error">{{ error }}</p>

            <div class="modal-foot">
                <button class="btn btn-ghost" @click="emit('close')">{{ $t('common.cancel') }}</button>
                <button class="btn" :disabled="saving" @click="save">{{ saving ? $t('common.saving') : $t('common.save') }}</button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.modal-backdrop { position: fixed; inset: 0; background: rgba(5,8,14,0.55); display: flex; align-items: center; justify-content: center; padding: 20px; z-index: 100; }
.modal { width: 100%; max-width: 600px; max-height: 92vh; display: flex; flex-direction: column; }
.modal-head { display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; border-bottom: 1px solid var(--line); }
.modal-head h2 { font-size: 1.1rem; }
.icon-close { background: var(--surface-2); border: none; width: 32px; height: 32px; border-radius: 8px; cursor: pointer; color: var(--ink); }
.modal-body { padding: 22px 24px; overflow-y: auto; display: flex; flex-direction: column; gap: 22px; }
.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
label { display: flex; flex-direction: column; gap: 6px; font-size: 0.82rem; font-weight: 600; color: var(--ink-soft); }
.section-label { font-size: 0.82rem; font-weight: 600; color: var(--ink-soft); display: block; margin-bottom: 10px; }

.role-tabs { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.role-tabs button { text-align: left; padding: 13px 16px; border: 1px solid var(--line); border-radius: 12px; background: var(--surface-2); cursor: pointer; transition: border-color 0.15s; }
.role-tabs button.active { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(59,130,246,0.15); }
.role-tabs b { display: block; color: var(--ink); font-size: 0.92rem; }
.role-tabs small { color: var(--ink-soft); font-size: 0.76rem; }

.perm-group { margin-bottom: 14px; }
.perm-group-title { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--ink-soft); margin-bottom: 8px; }
.perm-item { flex-direction: row; align-items: center; gap: 9px; font-weight: 500; color: var(--ink); padding: 5px 0; font-size: 0.88rem; }
.admin-note { display: flex; align-items: center; gap: 9px; color: var(--ink-soft); font-size: 0.88rem; background: var(--surface-2); padding: 14px 16px; border-radius: 10px; }
.form-error { color: var(--red); font-size: 0.86rem; padding: 0 24px; }
.modal-foot { display: flex; justify-content: flex-end; gap: 12px; padding: 18px 24px; border-top: 1px solid var(--line); }

@media (max-width: 520px) { .grid2, .role-tabs { grid-template-columns: 1fr; } }
</style>
