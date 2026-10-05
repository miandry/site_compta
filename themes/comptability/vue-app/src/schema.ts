/**
 * Modèle Drupal printblue (opérations du relevé + personnes) consommé via
 * api_solutions. Seul ce fichier connaît les machine names des champs.
 */

export type Vocabulary = 'operation_type' | 'category' | 'method_payment' | 'caisse'

export type BundleName = 'operation' | 'person' | 'event'

export type FieldKind =
  | 'text'
  | 'textarea'
  | 'email'
  | 'tel'
  | 'number'
  | 'money'
  | 'date'
  | 'datetime'
  | 'time'
  | 'boolean'
  | 'options'
  | 'term'
  | 'node'
  | 'file'
  | 'image'

export type FormValue = string | number | boolean | string[]
export type FormValues = Record<string, FormValue>

export interface LookupItem {
  id: string
  label: string
  values: FormValues
}

export interface FieldDef {
  key: string
  label: string
  /** En-tête de colonne plus court que le libellé du formulaire. */
  columnLabel?: string
  kind: FieldKind
  required?: boolean
  vocabulary?: Vocabulary
  target?: BundleName
  targetFilter?: (item: LookupItem) => boolean
  options?: Record<string, string>
  placeholder?: string
  /** Image : une seule (cardinalité 1) par défaut à plusieurs. */
  multiple?: boolean
  /** Terme / nœud : bouton « + » pour créer l'élément depuis le formulaire. */
  creatable?: boolean
  /** Calculé côté Drupal : affiché mais jamais envoyé à l'enregistrement. */
  readonly?: boolean
  /** Booléen : libellés [vrai, faux] (défaut Actif / Inactif). */
  booleanLabels?: [string, string]
  /** Champ du formulaire seulement : ni lu depuis Drupal ni envoyé. */
  virtual?: boolean
  /** Affiché (et obligatoire si `required`) seulement quand la condition est vraie. */
  showIf?: (values: FormValues) => boolean
}

export interface DeriveContext {
  termLabel: (vocabulary: Vocabulary, id: string) => string
  lookup: (bundle: BundleName, id: string) => LookupItem | undefined
}

/** Liste mobile (façon Android) : clés de champs, la première valeur non vide l'emporte. */
export interface MobileListDef {
  titleKeys: string[]
  subtitleKeys: string[]
  amountKey?: string
  /** Petit texte affiché sous le montant (ex. auteur). */
  amountNoteKey?: string
  dateKey?: string
  avatarKey: string
}

export interface BundleDef {
  bundle: BundleName
  mobile?: MobileListDef
  /** Champ Entrée / Sortie : signe et couleur des montants. */
  directionKey?: string
  /** Page détail : records d'un autre bundle qui référencent celui-ci. */
  related?: { bundle: BundleName; key: string; label: string }
  /** Page détail : historique des révisions (module Drupal comptabilty). */
  history?: boolean
  label: string
  plural: string
  description: string
  createLabel: string
  fields: FieldDef[]
  steps: { label: string; fields: string[] }[]
  columns: string[]
  filters: string[]
  /** Champ date filtrable par période (du … au …). */
  dateFilterKey?: string
  sortField: string
  sortOrder: 'ASC' | 'DESC'
  titleFrom?: (values: FormValues, ctx: DeriveContext) => string
  derive?: (values: FormValues, changed: string, ctx: DeriveContext) => void
  validate?: (values: FormValues) => string
  /** Formulaire : remplit les champs virtuels à l'ouverture (création ou modification). */
  hydrate?: (values: FormValues) => void
  /** Formulaire : calcule les champs Drupal à partir des champs virtuels avant l'envoi. */
  finalize?: (values: FormValues) => void
}

/** `adminOnly` : page de gestion réservée aux administrateurs (le backend refuse aussi l'écriture). */
export const VOCABULARIES: Record<Vocabulary, { label: string; description: string; adminOnly?: boolean }> = {
  operation_type: {
    label: "Types d'opération",
    description: "Nature de l'opération : Versement espèces, Virement reçu, Virement émis, Retrait, Chèque, Frais bancaires.",
  },
  category: {
    adminOnly: true,
    label: 'Catégories',
    description: "Catégorie de l'opération pour le classement et les rapports (ex. Écolage, Vente, Salaire, Fournisseur).",
  },
  method_payment: {
    adminOnly: true,
    label: 'Moyens de paiement',
    description: "Moyen utilisé pour l'opération : Espèces, Virement bancaire, Chèque, MVola, Orange Money, Airtel Money.",
  },
  caisse: {
    adminOnly: true,
    label: 'Caisses',
    description: 'Caisse ou compte de trésorerie concerné (ex. Caisse principale, Caisse secondaire, Banque BOA, Compte MVola).',
  },
}

/** Valeurs de la liste Drupal field_mouvement_argent. */
export const MOUVEMENTS = { Entree: 'Entrée', Sortie: 'Sortie' }

/** Valeurs de la liste Drupal field_event_repeat (module event_reminder). */
export const REPEATS = {
  once: 'Une seule fois',
  daily: 'Chaque jour',
  weekly: 'Chaque semaine',
  monthly: 'Chaque mois',
  yearly: 'Chaque année',
}

/** Jours de la semaine -> Date.getDay(). */
const WEEKDAYS = { mon: 'Lundi', tue: 'Mardi', wed: 'Mercredi', thu: 'Jeudi', fri: 'Vendredi', sat: 'Samedi', sun: 'Dimanche' }
const WEEKDAY_INDEX: Record<string, number> = { sun: 0, mon: 1, tue: 2, wed: 3, thu: 4, fri: 5, sat: 6 }

const isRepeat = (v: FormValues, ...repeats: string[]) => repeats.includes(String(v.field_event_repeat || 'once'))
const pad2 = (n: number) => String(n).padStart(2, '0')
const localInput = (d: Date) => `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}T${pad2(d.getHours())}:${pad2(d.getMinutes())}`
const daysInMonth = (year: number, month: number) => new Date(year, month + 1, 0).getDate()

/**
 * Prochaine occurrence (heure locale, >= maintenant) d'un événement répété,
 * au format <input type="datetime-local">. Le jour du mois est ramené au
 * dernier jour des mois plus courts (31 -> 30 avril, 28/29 février).
 */
export function nextEventOccurrence(v: FormValues, now = new Date()): string {
  const [hh, mm] = String(v._time || '00:00').split(':').map(Number)
  const at = (y: number, m: number, d: number) => new Date(y, m, Math.min(d, daysInMonth(y, m)), hh, mm)
  const y = now.getFullYear()
  const m = now.getMonth()
  let next: Date
  switch (String(v.field_event_repeat)) {
    case 'daily':
      next = at(y, m, now.getDate())
      if (next <= now) next = at(y, m, now.getDate() + 1)
      break
    case 'weekly': {
      const delta = (WEEKDAY_INDEX[String(v._weekday)] - now.getDay() + 7) % 7
      next = new Date(y, m, now.getDate() + delta, hh, mm)
      if (next <= now) next = new Date(y, m, now.getDate() + delta + 7, hh, mm)
      break
    }
    case 'monthly': {
      const day = Number(v._monthday)
      next = at(y, m, day)
      if (next <= now) next = at(y, m + 1, day)
      break
    }
    case 'yearly': {
      const [, month, day] = String(v._yearday).split('-').map(Number)
      next = at(y, month - 1, day)
      if (next <= now) next = at(y + 1, month - 1, day)
      break
    }
    default:
      return String(v.field_event_date ?? '')
  }
  return localInput(next)
}

/** « Sortie » (ou « Débit ») -> sortie d'argent. */
export function isDebitLabel(label: string): boolean {
  return /sortie|d[ée]bit/i.test(label)
}

export const BUNDLES: Record<BundleName, BundleDef> = {
  operation: {
    bundle: 'operation',
    label: 'Opération',
    plural: 'Opérations',
    description: 'Lignes du relevé : date, mouvement (entrée/sortie), montant, libellé, personne et justificatif.',
    createLabel: '+ Nouvelle opération',
    directionKey: 'field_mouvement_argent',
    history: true,
    fields: [
      { key: 'field_mouvement_argent', label: "Mouvement d'argent", columnLabel: 'Mouvement', kind: 'options', options: MOUVEMENTS, required: true },
      { key: 'field_amount', label: 'Montant (Ar)', columnLabel: 'Montant', kind: 'money', required: true },
      { key: 'field_label', label: 'Libellé', kind: 'textarea', placeholder: 'Écolage septembre — Rakoto (facultatif)' },
      { key: 'field_operation_date', label: 'Date opération', columnLabel: 'Date', kind: 'date', required: true },
      { key: 'field_person', label: 'Personne', kind: 'node', target: 'person', required: true, creatable: true },
      { key: 'field_operation_type', label: "Type d'opération", columnLabel: 'Type', kind: 'term', vocabulary: 'operation_type', creatable: true },
      { key: 'field_category', label: 'Catégorie', kind: 'term', vocabulary: 'category', required: true },
      { key: 'field_method_payment', label: 'Moyen de paiement', columnLabel: 'Moyen', kind: 'term', vocabulary: 'method_payment' },
      { key: 'field_caisse', label: 'Caisse', kind: 'term', vocabulary: 'caisse' },
      { key: 'field_image_prof', label: 'Image justificatif', columnLabel: 'Justificatif', kind: 'image', multiple: false },
      { key: 'uid', label: 'Auteur', kind: 'text', readonly: true },
    ],
    steps: [
      { label: 'Opération', fields: ['field_category', 'field_mouvement_argent', 'field_amount', 'field_label'] },
      { label: 'Date & personne', fields: ['field_operation_date', 'field_person'] },
      { label: 'Classement', fields: ['field_operation_type', 'field_method_payment', 'field_caisse'] },
      { label: 'Justificatif', fields: ['field_image_prof'] },
    ],
    columns: [
      'field_operation_date',
      'field_label',
      'field_person',
      'field_operation_type',
      'field_caisse',
      'field_mouvement_argent',
      'field_amount',
      'uid',
    ],
    filters: ['field_mouvement_argent', 'field_caisse', 'field_category', 'field_operation_type'],
    mobile: {
      titleKeys: ['field_label', 'title'],
      subtitleKeys: ['field_person', 'field_caisse'],
      amountKey: 'field_amount',
      amountNoteKey: 'uid',
      dateKey: 'field_operation_date',
      avatarKey: 'field_person',
    },
    dateFilterKey: 'field_operation_date',
    sortField: 'nid',
    sortOrder: 'DESC',
    // Drupal exige un titre : sans libellé, « Catégorie — Personne ».
    titleFrom: (v, ctx) => {
      const label = String(v.field_label ?? '').replace(/\s+/g, ' ').trim()
      if (label) return label.length > 120 ? `${label.slice(0, 117)}…` : label
      const category = v.field_category ? ctx.termLabel('category', String(v.field_category)) : ''
      const person = v.field_person ? ctx.lookup('person', String(v.field_person))?.label ?? '' : ''
      return [category, person].filter(Boolean).join(' — ') || 'Opération'
    },
  },

  person: {
    bundle: 'person',
    label: 'Personne',
    plural: 'Personnes',
    description: 'Personnes liées aux opérations.',
    createLabel: '+ Nouvelle personne',
    related: { bundle: 'operation', key: 'field_person', label: 'Opérations' },
    fields: [
      { key: 'title', label: 'Nom complet', kind: 'text', required: true, placeholder: 'Rakoto Jean' },
      { key: 'field_phone', label: 'Numéro de téléphone', columnLabel: 'Téléphone', kind: 'tel', placeholder: '034 00 000 00' },
      { key: 'field_field_email', label: 'Email', kind: 'email', placeholder: 'rakoto@exemple.mg' },
    ],
    steps: [{ label: 'Personne', fields: ['title', 'field_phone', 'field_field_email'] }],
    columns: ['title', 'field_phone', 'field_field_email'],
    mobile: {
      titleKeys: ['title'],
      subtitleKeys: ['field_phone', 'field_field_email'],
      avatarKey: 'title',
    },
    filters: [],
    sortField: 'title',
    sortOrder: 'ASC',
  },

  // Module Drupal event_reminder : email + SMS à la personne la veille de l'événement.
  event: {
    bundle: 'event',
    label: 'Événement',
    plural: 'Événements',
    description: "Rendez-vous et échéances : la personne concernée reçoit un rappel par email et SMS la veille.",
    createLabel: '+ Nouvel événement',
    fields: [
      { key: 'title', label: "Titre de l'événement", columnLabel: 'Événement', kind: 'text', required: true, placeholder: "Réunion des parents d'élèves" },
      { key: 'field_event_repeat', label: 'Répétition', kind: 'options', options: REPEATS, required: true },
      {
        key: 'field_event_date',
        label: "Date et heure de l'événement",
        columnLabel: 'Prochaine date',
        kind: 'datetime',
        required: true,
        showIf: (v) => isRepeat(v, 'once'),
      },
      { key: '_weekday', label: 'Jour de la semaine', kind: 'options', options: WEEKDAYS, required: true, virtual: true, showIf: (v) => isRepeat(v, 'weekly') },
      { key: '_monthday', label: 'Jour du mois (1 à 31)', kind: 'number', required: true, virtual: true, placeholder: '15', showIf: (v) => isRepeat(v, 'monthly') },
      { key: '_yearday', label: 'Date (jour et mois)', kind: 'date', required: true, virtual: true, showIf: (v) => isRepeat(v, 'yearly') },
      { key: '_time', label: 'Heure', kind: 'time', required: true, virtual: true, showIf: (v) => !isRepeat(v, 'once') },
      { key: 'field_person', label: 'Personne concernée', columnLabel: 'Personne', kind: 'node', target: 'person', required: true, creatable: true },
      { key: 'field_reminder_sent', label: 'Rappel envoyé', columnLabel: 'Rappel', kind: 'boolean', readonly: true, booleanLabels: ['Envoyé', 'En attente'] },
    ],
    steps: [
      {
        label: 'Événement',
        fields: ['title', 'field_event_repeat', 'field_event_date', '_weekday', '_monthday', '_yearday', '_time', 'field_person'],
      },
    ],
    columns: ['field_event_date', 'title', 'field_person', 'field_event_repeat', 'field_reminder_sent'],
    filters: ['field_event_repeat'],
    mobile: {
      titleKeys: ['title'],
      subtitleKeys: ['field_person', 'field_event_repeat', 'field_reminder_sent'],
      dateKey: 'field_event_date',
      avatarKey: 'field_person',
    },
    dateFilterKey: 'field_event_date',
    sortField: 'field_event_date',
    sortOrder: 'DESC',
    // Champs de planification déduits de la prochaine occurrence enregistrée.
    hydrate: (v) => {
      if (!v.field_event_repeat) v.field_event_repeat = 'once'
      const date = String(v.field_event_date || '')
      if (!date) return
      const d = new Date(date)
      v._time = date.slice(11, 16)
      v._weekday = Object.keys(WEEKDAY_INDEX).find((k) => WEEKDAY_INDEX[k] === d.getDay()) ?? ''
      v._monthday = d.getDate()
      v._yearday = date.slice(0, 10)
    },
    finalize: (v) => {
      v.field_event_date = nextEventOccurrence(v)
    },
    validate: (v) => {
      const day = Number(v._monthday)
      if (isRepeat(v, 'monthly') && !(Number.isInteger(day) && day >= 1 && day <= 31)) return 'Le jour du mois doit être compris entre 1 et 31.'
      return ''
    },
  },
}

export function fieldDef(def: BundleDef, key: string): FieldDef {
  const field = def.fields.find((f) => f.key === key)
  if (!field) throw new Error(`Champ inconnu ${def.bundle}.${key}`)
  return field
}
