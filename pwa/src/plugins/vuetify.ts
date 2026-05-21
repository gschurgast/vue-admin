import 'vuetify/styles'
import { createVuetify } from 'vuetify'
import * as components from 'vuetify/components'
import * as labsComponents from 'vuetify/labs/components'
import * as directives from 'vuetify/directives'
import { aliases, mdi } from 'vuetify/iconsets/mdi'

const youPimTheme = {
    dark: false,
    colors: {
        background: '#F6F9FC',
        surface: '#FFFFFF',
        'surface-light': '#F2F6FA',
        'surface-variant': '#ECF2FF',
        'on-surface': '#2A3547',
        'on-surface-variant': '#5A6A85',
        primary: '#5D87FF',
        'primary-darken-1': '#4570EA',
        secondary: '#49BEFF',
        'secondary-darken-1': '#23AFDB',
        success: '#13DEB9',
        info: '#539BFF',
        warning: '#FFAE1F',
        error: '#FA896B',
        'on-primary': '#FFFFFF',
        'on-secondary': '#FFFFFF',
        'on-success': '#FFFFFF',
        'on-warning': '#FFFFFF',
        'on-error': '#FFFFFF',
    },
    variables: {
        'border-color': '#E5EAEF',
        'border-opacity': 1,
        'high-emphasis-opacity': 0.87,
        'medium-emphasis-opacity': 0.65,
        'disabled-opacity': 0.38,
        'theme-on-background': '#2A3547',
    },
}

export default createVuetify({
    components: {
        ...components,
        ...labsComponents,
    },
    directives,
    icons: {
        defaultSet: 'mdi',
        aliases,
        sets: {
            mdi,
        },
    },
    theme: {
        defaultTheme: 'youPimTheme',
        themes: {
            youPimTheme,
        },
    },
    defaults: {
        VMain: {
            class: 'bg-background',
        },
        VAppBar: {
            color: 'surface',
            flat: true,
            density: 'comfortable',
        },
        VNavigationDrawer: {
            color: 'surface',
            border: 'end',
        },
        VCard: {
            rounded: 'lg',
            elevation: 0,
            border: 'thin',
        },
        VBtn: {
            rounded: 'lg',
            style: 'text-transform: none; letter-spacing: 0;',
        },
        VChip: {
            rounded: 'pill',
        },
        VTextField: {
            variant: 'outlined',
            density: 'comfortable',
            rounded: 'lg',
            color: 'primary',
        },
        VTextarea: {
            variant: 'outlined',
            density: 'comfortable',
            rounded: 'lg',
            color: 'primary',
        },
        VSelect: {
            variant: 'outlined',
            density: 'comfortable',
            rounded: 'lg',
            color: 'primary',
        },
        VAutocomplete: {
            variant: 'outlined',
            density: 'comfortable',
            rounded: 'lg',
            color: 'primary',
        },
        VCombobox: {
            variant: 'outlined',
            density: 'comfortable',
            rounded: 'lg',
            color: 'primary',
        },
        VList: {
            density: 'comfortable',
            rounded: 'lg',
        },
        VListItem: {
            rounded: 'lg',
        },
        VDataTable: {
            density: 'comfortable',
        },
        VTooltip: {
            location: 'bottom',
        },
    },
})