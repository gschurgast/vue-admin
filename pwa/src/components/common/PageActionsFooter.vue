<template>
  <div class="page-actions-footer">
    <div class="page-actions-footer__inner">
      <slot />
    </div>
  </div>
</template>

<style scoped>
.page-actions-footer {
  position: sticky;
  bottom: 0;
  left: 0;
  right: 0;
  z-index: 4;
  margin: 16px -12px -12px; /* bleed into the v-main padding so the bar spans full width */
  padding: 12px 24px;
  background: rgb(var(--v-theme-surface));
  border-top: 1px solid rgba(var(--v-theme-on-surface), 0.08);
  box-shadow: 0 -6px 16px rgba(20, 30, 60, 0.06);
  backdrop-filter: blur(6px);
}
.page-actions-footer__inner {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 16px;
  min-height: 48px;
}
</style>
