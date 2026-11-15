<script setup lang="ts">
defineProps<{ page: number; last: number }>()
defineEmits<{ change: [page: number] }>()
</script>

<template>
  <div v-if="last > 1" class="flex items-center justify-center gap-2 py-4">
    <button class="btn btn-secondary" :disabled="page <= 1" @click="$emit('change', page - 1)">Назад</button>
    <span class="px-2 text-sm text-slate-500">{{ page }} из {{ last }}</span>
    <button class="btn btn-secondary" :disabled="page >= last" @click="$emit('change', page + 1)">Вперёд</button>
  </div>
</template>
