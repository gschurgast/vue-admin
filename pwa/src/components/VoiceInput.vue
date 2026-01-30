<template>
  <v-btn
    :icon="isRecording ? 'mdi-stop' : 'mdi-microphone'"
    :size="size"
    :color="isRecording ? 'error' : color"
    :variant="variant"
    :disabled="disabled || !isSupported"
    :title="isRecording ? t('ai.stopRecording') : t('ai.startRecording')"
    @click="toggleRecording"
    class="voice-input-btn"
  >
    <v-icon :class="{ 'recording-pulse': isRecording }">
      {{ isRecording ? 'mdi-stop' : 'mdi-microphone' }}
    </v-icon>
    <v-tooltip v-if="!isSupported" activator="parent" location="top">
      {{ t('ai.speechNotSupported') }}
    </v-tooltip>
  </v-btn>
</template>

<script setup lang="ts">
import { ref, computed, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'

interface Props {
  size?: 'x-small' | 'small' | 'default' | 'large' | 'x-large'
  color?: string
  variant?: 'flat' | 'text' | 'elevated' | 'tonal' | 'outlined' | 'plain'
  disabled?: boolean
  lang?: string
  continuous?: boolean
  interimResults?: boolean
  autoSend?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  size: 'small',
  color: 'default',
  variant: 'text',
  disabled: false,
  lang: 'fr-FR',
  continuous: true,
  interimResults: true,
  autoSend: false
})

const emit = defineEmits<{
  'transcript': [text: string, isFinal: boolean]
  'start': []
  'stop': []
  'error': [error: string]
  'complete': [text: string]
}>()

const { t } = useI18n()

// Check browser support
const SpeechRecognition = (window as any).SpeechRecognition || (window as any).webkitSpeechRecognition
const isSupported = computed(() => !!SpeechRecognition)

const isRecording = ref(false)
let recognition: any = null
let fullTranscript = ''
let manualStop = false

function startRecording() {
  if (!SpeechRecognition) {
    emit('error', 'Speech recognition not supported')
    return
  }

  fullTranscript = ''
  manualStop = false

  recognition = new SpeechRecognition()
  recognition.lang = props.lang
  // In autoSend mode, disable continuous so it stops when user pauses
  recognition.continuous = props.autoSend ? false : props.continuous
  recognition.interimResults = props.interimResults

  recognition.onstart = () => {
    isRecording.value = true
    emit('start')
  }

  recognition.onresult = (event: any) => {
    let interimTranscript = ''
    let finalTranscript = ''

    for (let i = event.resultIndex; i < event.results.length; i++) {
      const transcript = event.results[i][0].transcript
      if (event.results[i].isFinal) {
        finalTranscript += transcript
      } else {
        interimTranscript += transcript
      }
    }

    if (finalTranscript) {
      fullTranscript += (fullTranscript ? ' ' : '') + finalTranscript
      emit('transcript', finalTranscript, true)
    } else if (interimTranscript) {
      emit('transcript', interimTranscript, false)
    }
  }

  recognition.onerror = (event: any) => {
    console.error('Speech recognition error:', event.error)
    isRecording.value = false

    if (event.error !== 'aborted') {
      emit('error', event.error)
    }
  }

  recognition.onend = () => {
    isRecording.value = false
    emit('stop')

    // In autoSend mode, emit complete with full transcript when recognition ends naturally
    if (props.autoSend && !manualStop && fullTranscript.trim()) {
      emit('complete', fullTranscript.trim())
    }
  }

  recognition.start()
}

function stopRecording() {
  manualStop = true
  if (recognition) {
    recognition.stop()
    recognition = null
  }
  isRecording.value = false
}

function toggleRecording() {
  if (isRecording.value) {
    stopRecording()
  } else {
    startRecording()
  }
}

// Cleanup on unmount
onUnmounted(() => {
  stopRecording()
})

// Expose methods for parent component
defineExpose({
  startRecording,
  stopRecording,
  isRecording,
  isSupported
})
</script>

<style scoped>
.voice-input-btn {
  transition: all 0.2s ease;
}

.recording-pulse {
  animation: pulse 1s infinite;
}

@keyframes pulse {
  0% {
    opacity: 1;
  }
  50% {
    opacity: 0.5;
  }
  100% {
    opacity: 1;
  }
}
</style>