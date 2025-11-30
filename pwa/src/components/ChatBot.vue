<template>
  <v-navigation-drawer
      v-model="isOpen"
      location="right"
      temporary
      width="400"
  >
    <v-toolbar :title="t('ai.title')" density="compact" color="primary">
      <template v-slot:append>
        <v-btn icon="mdi-refresh" @click="startNewConversation" :title="t('ai.newConversation')">
          <v-icon>mdi-refresh</v-icon>
        </v-btn>
        <v-btn icon="mdi-close" @click="close"></v-btn>
      </template>
    </v-toolbar>

    <v-card-text class="chat-container">
      <div ref="messagesContainer" class="messages-list">
        <div v-for="(msg, index) in messages" :key="index" class="message-wrapper">
          <!-- User message -->
          <div class="message user-message">
            <v-icon size="small" class="mr-2">mdi-account</v-icon>
            <div class="message-content">{{ msg.message }}</div>
          </div>
          
          <!-- AI response -->
          <div v-if="msg.response" class="message ai-message">
            <v-icon size="small" class="mr-2" color="primary">mdi-robot</v-icon>
            <div class="message-content">
              {{ msg.response }}
              <v-btn 
                icon 
                size="x-small" 
                variant="text" 
                class="copy-btn"
                @click="copyToClipboard(msg.response)"
                :title="t('ai.copy')"
              >
                <v-icon size="small">mdi-content-copy</v-icon>
              </v-btn>
            </div>
          </div>
          
          <!-- Loading indicator -->
          <div v-else-if="msg.loading" class="message ai-message">
            <v-icon size="small" class="mr-2" color="primary">mdi-robot</v-icon>
            <div class="message-content">
              <v-progress-circular indeterminate size="20" width="2"></v-progress-circular>
              <span class="ml-2">{{ t('ai.thinking') }}</span>
            </div>
          </div>
        </div>
      </div>
    </v-card-text>

    <v-card-actions class="chat-input-container">
      <div class="input-wrapper">
        <v-textarea
          v-model="currentMessage"
          :placeholder="t('ai.placeholder')"
          variant="outlined"
          density="compact"
          hide-details
          rows="1"
          auto-grow
          max-rows="5"
          @keydown.enter.ctrl="sendMessage"
          @keydown.enter.shift="sendMessage"
          :disabled="loading"
        >
          <template v-slot:append-inner>
            <v-btn
              icon="mdi-send"
              size="small"
              color="primary"
              :disabled="!currentMessage.trim() || loading"
              @click="sendMessage"
            ></v-btn>
          </template>
        </v-textarea>
        <div class="send-hint">{{ t('ai.sendHint') }}</div>
      </div>
    </v-card-actions>
  </v-navigation-drawer>
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'

interface Props {
  modelValue: boolean
}

interface ChatMessage {
  message: string
  response?: string
  loading?: boolean
}

const props = defineProps<Props>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
}>()

const { t } = useI18n()
const currentMessage = ref('')
const messages = ref<ChatMessage[]>([])
const loading = ref(false)
const messagesContainer = ref<HTMLElement | null>(null)

const isOpen = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value)
})

function close() {
  isOpen.value = false
}

function startNewConversation() {
  messages.value = []
}

function copyToClipboard(text: string) {
  navigator.clipboard.writeText(text).then(() => {
    // Could add a toast notification here if desired
  })
}

async function sendMessage() {
  if (!currentMessage.value.trim() || loading.value) return

  const userMessage = currentMessage.value
  currentMessage.value = ''
  
  // Add user message with loading state
  messages.value.push({
    message: userMessage,
    loading: true
  })

  loading.value = true
  scrollToBottom()

  try {
    const response = await axios.post('http://localhost:8080/api/chat', {
      message: userMessage
    }, {
      headers: {
        'Content-Type': 'application/ld+json',
        'Accept': 'application/ld+json'
      }
    })

    // Update the last message with the response
    const lastMessage = messages.value[messages.value.length - 1]
    lastMessage.response = response.data.response
    lastMessage.loading = false

    scrollToBottom()
  } catch (error) {
    console.error('Chat error:', error)
    const lastMessage = messages.value[messages.value.length - 1]
    lastMessage.response = t('ai.error')
    lastMessage.loading = false
  } finally {
    loading.value = false
  }
}

function scrollToBottom() {
  nextTick(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
    }
  })
}

// Scroll to bottom when drawer opens
watch(() => props.modelValue, (isOpen) => {
  if (isOpen) {
    scrollToBottom()
  }
})
</script>

<style scoped>
.chat-container {
  height: calc(100vh - 200px); /* More space for app bar, toolbar, and input */
  display: flex;
  flex-direction: column;
  padding: 0 !important;
}

.messages-list {
  flex: 1;
  overflow-y: auto;
  padding: 16px;
  min-height: 0; /* Important for flex scrolling */
}

.message-wrapper {
  margin-bottom: 16px;
}

.message {
  display: flex;
  align-items: flex-start;
  margin-bottom: 8px;
}

.user-message {
  justify-content: flex-end;
}

.user-message .message-content {
  background-color: rgb(var(--v-theme-primary));
  color: white;
  border-radius: 16px 16px 4px 16px;
}

.ai-message .message-content {
  background-color: #ffffff;
  color: #000000;
  border-radius: 16px 16px 16px 4px;
  border: 1px solid rgba(0, 0, 0, 0.12);
  position: relative;
}

.ai-message .message-content:hover .copy-btn {
  opacity: 1;
}

.copy-btn {
  position: absolute;
  top: 4px;
  right: 4px;
  opacity: 0;
  transition: opacity 0.2s;
}

.message-content {
  padding: 12px 16px;
  max-width: 80%;
  word-wrap: break-word;
}

.chat-input-container {
  padding: 16px;
  border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  background-color: rgb(var(--v-theme-surface));
}

.input-wrapper {
  width: 100%;
}

.send-hint {
  font-size: 11px;
  color: #9e9e9e;
  margin-top: 4px;
  text-align: right;
}
</style>
