<script setup lang="ts">
import {
  ArcElement,
  BarElement,
  CategoryScale,
  Chart as ChartJS,
  Filler,
  Legend,
  LinearScale,
  LineElement,
  PointElement,
  Tooltip,
  type ChartOptions,
  type TooltipItem,
} from 'chart.js'
import { computed } from 'vue'
import { Bar, Doughnut, Line } from 'vue-chartjs'
import { formatMoney } from '@/utils/format'

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  BarElement,
  ArcElement,
  Tooltip,
  Legend,
  Filler,
)

export type MonthPoint = { key: string; label: string; entree: number; sortie: number }
export type CategoryPoint = { label: string; total: number }

const props = defineProps<{
  byMonth: MonthPoint[]
  creditsByCategory: CategoryPoint[]
}>()

const moneyTick = (v: string | number) => {
  const n = Number(v)
  if (!Number.isFinite(n)) return ''
  if (Math.abs(n) >= 1_000_000) return `${Math.round(n / 1_000_000)} M`
  if (Math.abs(n) >= 1_000) return `${Math.round(n / 1_000)} k`
  return String(Math.round(n))
}

const monthChart = computed(() => ({
  labels: props.byMonth.map((m) => m.label),
  datasets: [
    {
      label: 'Entrées',
      data: props.byMonth.map((m) => m.entree),
      borderColor: '#10b981',
      backgroundColor: 'rgba(16, 185, 129, 0.18)',
      fill: true,
      tension: 0.35,
      pointRadius: 3,
      pointBackgroundColor: '#10b981',
    },
    {
      label: 'Sorties',
      data: props.byMonth.map((m) => m.sortie),
      borderColor: '#ef4444',
      backgroundColor: 'rgba(239, 68, 68, 0.12)',
      fill: true,
      tension: 0.35,
      pointRadius: 3,
      pointBackgroundColor: '#ef4444',
    },
  ],
}))

const monthOptions = computed<ChartOptions<'line'>>(() => ({
  responsive: true,
  maintainAspectRatio: false,
  interaction: { mode: 'index', intersect: false },
  plugins: {
    legend: {
      position: 'bottom',
      labels: { color: '#6b7280', boxWidth: 12, usePointStyle: true, padding: 16 },
    },
    tooltip: {
      callbacks: {
        label: (ctx: TooltipItem<'line'>) =>
          `${ctx.dataset.label ?? ''}: ${formatMoney(ctx.parsed.y ?? 0)}`,
      },
    },
  },
  scales: {
    x: {
      grid: { display: false },
      ticks: { color: '#6b7280', maxRotation: 0 },
    },
    y: {
      beginAtZero: true,
      grid: { color: 'rgba(163, 177, 198, 0.35)' },
      ticks: { color: '#6b7280', callback: moneyTick },
    },
  },
}))

const CATEGORY_COLORS = [
  '#10b981', '#34d399', '#059669', '#6ee7b7', '#047857',
  '#a7f3d0', '#14b8a6', '#2dd4bf', '#0d9488', '#5eead4',
]

const categoryChart = computed(() => {
  const rows = props.creditsByCategory.slice(0, 8)
  return {
    labels: rows.map((r) => r.label),
    datasets: [
      {
        data: rows.map((r) => r.total),
        backgroundColor: rows.map((_, i) => CATEGORY_COLORS[i % CATEGORY_COLORS.length]),
        borderWidth: 0,
        hoverOffset: 6,
      },
    ],
  }
})

const categoryBar = computed(() => {
  const rows = props.creditsByCategory.slice(0, 8)
  return {
    labels: rows.map((r) => r.label),
    datasets: [
      {
        label: 'Entrées',
        data: rows.map((r) => r.total),
        backgroundColor: '#10b981',
        borderRadius: 8,
        maxBarThickness: 28,
      },
    ],
  }
})

const categoryOptions = computed<ChartOptions<'bar'>>(() => ({
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: {
      callbacks: {
        label: (ctx: TooltipItem<'bar'>) => formatMoney(ctx.parsed.y ?? 0),
      },
    },
  },
  scales: {
    x: {
      grid: { display: false },
      ticks: { color: '#6b7280', maxRotation: 45, minRotation: 0 },
    },
    y: {
      beginAtZero: true,
      grid: { color: 'rgba(163, 177, 198, 0.35)' },
      ticks: { color: '#6b7280', callback: moneyTick },
    },
  },
}))

const doughnutOptions = computed<ChartOptions<'doughnut'>>(() => ({
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      position: 'bottom',
      labels: { color: '#6b7280', boxWidth: 10, padding: 12, font: { size: 11 } },
    },
    tooltip: {
      callbacks: {
        label: (ctx: TooltipItem<'doughnut'>) => {
          const data = ctx.dataset.data as number[]
          const total = data.reduce((s, n) => s + n, 0) || 1
          const value = Number(ctx.parsed) || 0
          const pct = Math.round((value / total) * 100)
          return `${ctx.label}: ${formatMoney(value)} (${pct} %)`
        },
      },
    },
  },
}))

const useDoughnut = computed(() => props.creditsByCategory.length > 0 && props.creditsByCategory.length <= 6)
</script>

<template>
  <div class="grid gap-6 xl:grid-cols-2">
    <section class="card p-5" data-testid="dash-month-chart">
      <h2 class="mb-1 font-display text-lg font-semibold text-neu-dark">Évolution dans le temps</h2>
      <p class="mb-4 text-sm text-neu-muted">Entrées et sorties par mois</p>
      <div v-if="byMonth.length" class="h-64 sm:h-72">
        <Line :data="monthChart" :options="monthOptions" />
      </div>
      <p v-else class="py-10 text-center text-sm text-neu-muted">Pas encore de données mensuelles.</p>
    </section>

    <section class="card p-5" data-testid="dash-credits-category">
      <h2 class="mb-1 font-display text-lg font-semibold text-neu-dark">Entrées par catégorie</h2>
      <p class="mb-4 text-sm text-neu-muted">Répartition des entrées</p>
      <div v-if="creditsByCategory.length" class="h-64 sm:h-72">
        <Doughnut v-if="useDoughnut" :data="categoryChart" :options="doughnutOptions" />
        <Bar v-else :data="categoryBar" :options="categoryOptions" />
      </div>
      <p v-else class="py-10 text-center text-sm text-neu-muted">Aucune entrée enregistrée.</p>
    </section>
  </div>
</template>
