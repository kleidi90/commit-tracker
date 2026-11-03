<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{ page: number; perPage: number; total: number }>()
const emit = defineEmits<{ change: [page: number] }>()

const pageCount = computed(() => Math.max(1, Math.ceil(props.total / props.perPage)))
</script>

<template>
  <nav class="pagination" aria-label="Pages">
    <button type="button" :disabled="page <= 1" @click="emit('change', page - 1)">Previous</button>
    <span>Page {{ page }} of {{ pageCount }} ({{ total }} in total)</span>
    <button type="button" :disabled="page >= pageCount" @click="emit('change', page + 1)">Next</button>
  </nav>
</template>
