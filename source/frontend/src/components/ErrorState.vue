<script setup lang="ts">
defineProps<{ message: string }>()
defineEmits<{ retry: [] }>()
</script>

<template>
  <div class="state state-error" role="alert">
    <p>{{ message }}</p>
    <button type="button" @click="$emit('retry')">Try again</button>
  </div>
</template>
