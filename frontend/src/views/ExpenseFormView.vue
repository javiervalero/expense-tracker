<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'

// Placeholder until WP9: one heading for both the new and the edit route.
const route = useRoute()
const heading = computed(() => (route.name === 'expense-edit' ? 'Edit expense' : 'New expense'))
</script>

<template>
  <h1>{{ heading }}</h1>
</template>
