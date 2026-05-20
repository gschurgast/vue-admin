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
          <template v-slot:prepend-inner>
            <VoiceInput
              ref="voiceInputRef"
              :disabled="loading"
              :lang="voiceLang"
              auto-send
              @transcript="onTranscript"
              @start="onRecordingStart"
              @stop="onRecordingStop"
              @complete="onVoiceComplete"
            />
          </template>
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
        <div class="send-hint">
          <span v-if="isRecording" class="recording-hint">{{ t('ai.recording') }}</span>
          <span v-else>{{ t('ai.sendHint') }}</span>
        </div>
      </div>
    </v-card-actions>
  </v-navigation-drawer>
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import apiPlatform from '../services/apiPlatform'
import VoiceInput from './VoiceInput.vue'

const CONVERSATION_KEY = 'chat_conversation_id'

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

const { t, locale } = useI18n()
const route = useRoute()
const currentMessage = ref('')
const messages = ref<ChatMessage[]>([])
const loading = ref(false)
const messagesContainer = ref<HTMLElement | null>(null)
const isRecording = ref(false)
const voiceInputRef = ref<InstanceType<typeof VoiceInput> | null>(null)
const wasVoiceMessage = ref(false)
const conversationId = ref('')

// Build page context from current route
// Routes: /edit/:resource/:id, /show/:resource/:id, /resource/:resource
const pageContext = computed(() => {
  const params = route.params
  const pathParts = route.path.split('/').filter(Boolean)

  if (pathParts.length < 2) {
    return null
  }

  const firstPart = pathParts[0]
  let pageType: string
  let resourceType: string
  let resourceId: string | undefined

  if (firstPart === 'edit' || firstPart === 'show') {
    // /edit/Author/4 or /show/Author/4
    pageType = firstPart
    resourceType = (params.resource as string)?.toLowerCase()
    resourceId = params.id as string
  } else if (firstPart === 'resource') {
    // /resource/Author (list view)
    pageType = 'list'
    resourceType = (params.resource as string)?.toLowerCase()
    resourceId = undefined
  } else {
    return null
  }

  if (!resourceType) {
    return null
  }

  // Build IRI: /authors/4 (pluralize resource name)
  const pluralResource = resourceType.endsWith('s') ? resourceType : resourceType + 's'
  const resourceIri = resourceId ? `/${pluralResource}/${resourceId}` : null

  return {
    resourceType: pluralResource,
    resourceIri,
    pageType
  }
})

// Convert locale format: en_US -> en-US for Web Speech API
const voiceLang = computed(() => locale.value.replace('_', '-'))

function generateConversationId(): string {
  return crypto.randomUUID()
}

onMounted(async () => {
  // Get or create conversation ID
  const stored = localStorage.getItem(CONVERSATION_KEY)
  if (stored) {
    conversationId.value = stored
    // Load existing conversation history
    await loadConversationHistory()
  } else {
    conversationId.value = generateConversationId()
    localStorage.setItem(CONVERSATION_KEY, conversationId.value)
  }
})

async function loadConversationHistory() {
  if (!conversationId.value) return

  try {
    const response = await apiPlatform.client.get(`/api/conversations/${conversationId.value}`, {
      headers: {
        'Accept': 'application/ld+json'
      }
    })

    if (response.data?.messages && Array.isArray(response.data.messages)) {
      messages.value = response.data.messages.map((msg: { message: string; response: string }) => ({
        message: msg.message,
        response: msg.response,
        loading: false
      }))
      scrollToBottom()
    }
  } catch (error) {
    // If conversation not found or error, just start fresh
    console.log('No existing conversation found, starting fresh')
  }
}

const isOpen = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value)
})

function close() {
  isOpen.value = false
}

async function startNewConversation() {
  // Delete conversation from Redis
  if (conversationId.value) {
    try {
      await apiPlatform.client.delete(`/api/conversations/${conversationId.value}`)
    } catch (error) {
      console.error('Error deleting conversation:', error)
    }
  }

  // Generate new conversation ID
  conversationId.value = generateConversationId()
  localStorage.setItem(CONVERSATION_KEY, conversationId.value)

  // Clear local messages
  messages.value = []
}

function copyToClipboard(text: string) {
  navigator.clipboard.writeText(text).then(() => {
    // Could add a toast notification here if desired
  })
}

function onTranscript(text: string, isFinal: boolean) {
  if (isFinal) {
    currentMessage.value = (currentMessage.value + ' ' + text).trim()
  }
}

function onRecordingStart() {
  isRecording.value = true
}

function onRecordingStop() {
  isRecording.value = false
}

function onVoiceComplete(text: string) {
  currentMessage.value = text
  wasVoiceMessage.value = true
  sendMessage()
}

async function sendMessage() {
  if (!currentMessage.value.trim() || loading.value) return

  // Stop recording if active
  voiceInputRef.value?.stopRecording()

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
    const response = await apiPlatform.client.post('/api/chat', {
      message: userMessage,
      conversationId: conversationId.value,
      pageContext: pageContext.value
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

    // Restart recording if the message was sent via voice
    if (wasVoiceMessage.value) {
      wasVoiceMessage.value = false
      nextTick(() => {
        voiceInputRef.value?.startRecording()
      })
    }
  }
}

function scrollToBottom() {
  nextTick(() => {
    if (messagesContainer.value) {
      messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
    }
  })
}

// Handle drawer open/close
watch(() => props.modelValue, (isOpen) => {
  if (isOpen) {
    scrollToBottom()
  } else {
    // Stop recording when panel closes
    voiceInputRef.value?.stopRecording()
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

.recording-hint {
  color: #f44336;
  animation: blink 1s infinite;
}

@keyframes blink {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}
</style>
