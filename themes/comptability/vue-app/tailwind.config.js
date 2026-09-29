/** @type {import('tailwindcss').Config} */
/**
 * Tactile neumorphism + modern accents (soft red)
 * Classic surface #e0e5ec · Soft red #ef4444 · Success #10b981
 */
export default {
  content: ['./index.html', './src/**/*.{vue,ts}', '../templates/**/*.twig'],
  theme: {
    extend: {
      colors: {
        neu: {
          bg: '#e0e5ec',
          soft: '#e0e5ec',
          surface: '#e0e5ec',
          text: '#374151',
          muted: '#6b7280',
          dark: '#1f2937',
          shadow: '#a3b1c6',
        },
        accent: {
          DEFAULT: '#ef4444',
          soft: '#f87171',
          light: '#fecaca',
          lighter: '#fee2e2',
          dark: '#dc2626',
        },
        success: {
          DEFAULT: '#10b981',
          light: '#d1fae5',
          dark: '#059669',
        },
        brand: {
          50: '#fee2e2',
          100: '#fecaca',
          200: '#fca5a5',
          300: '#f87171',
          400: '#ef4444',
          500: '#ef4444',
          600: '#dc2626',
          700: '#b91c1c',
          800: '#991b1b',
          900: '#7f1d1d',
        },
        ink: {
          50: '#e0e5ec',
          100: '#d5dae3',
          200: '#c5ccd6',
          300: '#a3b1c6',
          400: '#6b7280',
          500: '#4b5563',
          600: '#374151',
          700: '#1f2937',
          800: '#111827',
          900: '#0b1220',
          950: '#030712',
        },
      },
      fontFamily: {
        sans: ['"Nunito Sans"', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        display: ['"Nunito Sans"', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        mono: ['"IBM Plex Mono"', 'ui-monospace', 'monospace'],
      },
      boxShadow: {
        'neu-out':
          '9px 9px 16px rgba(163, 177, 198, 0.6), -9px -9px 16px rgba(255, 255, 255, 0.85)',
        'neu-out-sm':
          '5px 5px 10px rgba(163, 177, 198, 0.55), -5px -5px 10px rgba(255, 255, 255, 0.9)',
        'neu-in':
          'inset 6px 6px 12px rgba(163, 177, 198, 0.55), inset -6px -6px 12px rgba(255, 255, 255, 0.85)',
        'neu-inset':
          'inset 6px 6px 12px rgba(163, 177, 198, 0.55), inset -6px -6px 12px rgba(255, 255, 255, 0.85)',
        'neu-accent':
          '6px 6px 14px rgba(239, 68, 68, 0.28), -4px -4px 10px rgba(255, 255, 255, 0.7)',
      },
      borderRadius: {
        neu: '1rem',
        'neu-xl': '1.5rem',
        pill: '9999px',
      },
    },
  },
  plugins: [],
}
