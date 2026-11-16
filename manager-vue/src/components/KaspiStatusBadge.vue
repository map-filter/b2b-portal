<script setup lang="ts">
defineProps<{ status: string; label: string }>()

const styles: Record<string, string> = {
  APPROVED_BY_BANK: 'bg-red-50 text-red-700 ring-red-200',
  ACCEPTED_BY_MERCHANT: 'bg-indigo-50 text-indigo-700 ring-indigo-200',
  ARRIVED: 'bg-amber-50 text-amber-700 ring-amber-200',
  COMPLETED: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
  CANCELLED: 'bg-slate-100 text-slate-500 ring-slate-200',
  CANCELLING: 'bg-slate-100 text-slate-500 ring-slate-200',
  KASPI_DELIVERY_RETURN_REQUESTED: 'bg-orange-50 text-orange-700 ring-orange-200',
  RETURNED: 'bg-orange-50 text-orange-700 ring-orange-200',
}
</script>

<template>
  <span
    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset"
    :class="styles[status] ?? 'bg-slate-100 text-slate-600 ring-slate-200'"
  >
    {{ label }}
  </span>
</template>
