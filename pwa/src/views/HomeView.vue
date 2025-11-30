<template>
  <v-container>
    <v-row>
      <v-col cols="12">
        <h1 class="text-h3 mb-4">API Platform Admin</h1>
        <p class="text-h6 mb-8">Manage your resources</p>
      </v-col>
    </v-row>

    <v-row>
      <v-col cols="12" md="6">
        <v-card to="/resource/Author" hover>
          <v-card-title class="bg-primary">
            <v-icon size="large" class="mr-2">mdi-account-group</v-icon>
            Authors
          </v-card-title>
          <v-card-text>
            <p class="mt-4">Manage author records, including names, birth dates, and status.</p>
          </v-card-text>
          <v-card-actions>
            <v-btn color="primary" variant="text">
              View Authors
              <v-icon end>mdi-arrow-right</v-icon>
            </v-btn>
          </v-card-actions>
        </v-card>
      </v-col>

      <v-col cols="12" md="6">
        <v-card to="/resource/Book" hover>
          <v-card-title class="bg-secondary">
            <v-icon size="large" class="mr-2">mdi-book-open-variant</v-icon>
            Books
          </v-card-title>
          <v-card-text>
            <p class="mt-4">Manage your book catalog, including titles, authors, descriptions, and publication dates.</p>
          </v-card-text>
          <v-card-actions>
            <v-btn color="secondary" variant="text">
              View Books
              <v-icon end>mdi-arrow-right</v-icon>
            </v-btn>
          </v-card-actions>
        </v-card>
      </v-col>
    </v-row>
  </v-container>
</template>

<script setup>
// Home page with resource navigation
</script>

<style scoped>
.v-card {
  transition: transform 0.2s;
}

.v-card:hover {
  transform: translateY(-4px);
}
</style>
