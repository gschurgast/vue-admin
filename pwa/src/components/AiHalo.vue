<template>
  <div
    class="ai-halo"
    :class="{ 'ai-halo--active': active }"
    :style="cssVars"
  >
    <slot />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

interface Props {
  /** Toggle the animated halo on/off. */
  active?: boolean
  /** Thickness of the halo in pixels. */
  thickness?: number
  /** Border radius to match the wrapped field. Pass a CSS value (e.g. "8px", "50%"). */
  radius?: string
  /** Rotation period in seconds. */
  speed?: number
}

const props = withDefaults(defineProps<Props>(), {
  active: false,
  thickness: 3,
  radius: '8px',
  speed: 3,
})

const cssVars = computed(() => ({
  '--halo-thickness': `${props.thickness}px`,
  '--halo-radius': props.radius,
  '--halo-speed': `${props.speed}s`,
}))
</script>

<style scoped>
.ai-halo {
  position: relative;
  border-radius: var(--halo-radius);
  isolation: isolate;
}

/* The halo lives just outside the wrapped element so the field's own styles
   (focus rings, borders) remain untouched. Hidden when inactive. */
.ai-halo::before {
  content: '';
  position: absolute;
  inset: calc(var(--halo-thickness) * -1);
  border-radius: calc(var(--halo-radius) + var(--halo-thickness));
  padding: var(--halo-thickness);
  background: conic-gradient(
    from var(--halo-angle, 0deg),
    #ff5f6d,
    #ffc371,
    #4ade80,
    #38bdf8,
    #a855f7,
    #ff5f6d
  );
  -webkit-mask:
    linear-gradient(#000 0 0) content-box,
    linear-gradient(#000 0 0);
  -webkit-mask-composite: xor;
  mask:
    linear-gradient(#000 0 0) content-box,
    linear-gradient(#000 0 0);
  mask-composite: exclude;
  opacity: 0;
  transition: opacity 0.18s ease;
  pointer-events: none;
  z-index: 0;
}

.ai-halo--active::before {
  opacity: 1;
  animation: ai-halo-spin var(--halo-speed) linear infinite;
}

/* Make the slotted content sit above the halo. */
.ai-halo > :slotted(*) {
  position: relative;
  z-index: 1;
}

@property --halo-angle {
  syntax: '<angle>';
  inherits: false;
  initial-value: 0deg;
}

@keyframes ai-halo-spin {
  to {
    --halo-angle: 360deg;
  }
}

/* Fallback for browsers without @property: rotate via transform on a separate
   layer using filter hue-rotate so colors still cycle. */
@supports not (background: conic-gradient(from 0deg, red, red)) {
  .ai-halo--active::before {
    animation: ai-halo-hue var(--halo-speed) linear infinite;
  }
  @keyframes ai-halo-hue {
    to { filter: hue-rotate(360deg); }
  }
}
</style>
