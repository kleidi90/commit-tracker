<script setup lang="ts">
withDefaults(defineProps<{ label?: string }>(), { label: 'Loading…' })
</script>

<template>
  <p class="state state-loading" role="status" aria-live="polite">{{ label }}</p>
</template>
