/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './*.php',
    './page-templates/**/*.php',
    './template-parts/**/*.php',
    './blocks/**/*.php',
    './inc/**/*.php',
    './build/**/*.js',
  ],
  theme: {
    extend: {
      colors: {
        'cf-ink': '#0F172A',
        'cf-ink-muted': '#64748B',
        'cf-surface': '#F8FAFC',
        'cf-border': '#E2E8F0',
        'cf-accent': '#1E4D7B',
        'cf-accent-soft': '#E8F0F8',
        'cf-success': '#15803D',
        'cf-warning': '#B45309',
        'cf-error': '#B91C1C',
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif'],
      },
    },
  },
  plugins: [],
};
