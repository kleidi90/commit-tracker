<script setup lang="ts">
defineProps<{ title: string }>()
</script>

<template>
  <div class="state state-empty">
    <p class="state-title">{{ title }}</p>
    <slot />
  </div>
</template>
