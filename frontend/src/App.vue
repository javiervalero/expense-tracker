<script setup lang="ts">
import { RouterView } from 'vue-router'

import ApiStatus from '@/components/ApiStatus.vue'
</script>

<template>
  <RouterView />
  <ApiStatus />
</template>
